<?php

namespace App\Bridges\Vuna;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Everything the bridge reads from the portal database, and nothing else: read-only queries over the
 * portal's own tables (staff, students, users, student_academics, course_regs, assigned_courses, ...).
 *
 * The portal has grown over the years, so every query first checks which tables and columns really
 * exist and only uses those. Who counts as "active" comes from config/vuna_bridge.php (rules).
 */
class Portal
{
    public const CONNECTION = 'vuna';

    /** @var array<string, list<string>> */
    private array $columns = [];

    private ?array $activeSemesterIds = null;

    private ?array $levels = null;

    private ?array $departmentFaculty = null;

    private bool $guarded = false;

    public function db(): Connection
    {
        $c = DB::connection(self::CONNECTION);
        if (! $this->guarded) {
            // second lock after the read-only session: nothing but reads ever leaves the bridge
            $c->beforeExecuting(function (string $query) {
                if (! preg_match('/^\s*\(?\s*(select|show|describe|desc|explain|pragma)\b/i', $query)
                    && ! (app()->environment('testing') && config('vuna_bridge.testing_allow_writes'))) {
                    throw new \RuntimeException('The portal bridge only reads from the portal database.');
                }
            });
            $this->guarded = true;
        }

        return $c;
    }

    /** The portal's name for one of its tables (config vuna_bridge.tables can rename them). */
    public function t(string $name): string
    {
        return (string) config("vuna_bridge.tables.$name", $name);
    }

    public function has(string $table, ?string $column = null): bool
    {
        $table = $this->t($table);
        $this->columns[$table] ??= array_map('strtolower', $this->db()->getSchemaBuilder()->getColumnListing($table));

        return $column === null ? $this->columns[$table] !== [] : in_array(strtolower($column), $this->columns[$table], true);
    }

    /** "alias.column" for each column that exists, so selects never name a missing column. */
    private function cols(string $table, string $alias, array $columns): array
    {
        $out = [];
        foreach ($columns as $as => $col) {
            if ($this->has($table, $col)) {
                $out[] = "$alias.$col".(is_string($as) ? " as $as" : '');
            }
        }

        return $out;
    }

    private function wrap(string $value): string
    {
        return $this->db()->getQueryGrammar()->wrap($value);
    }

    // ---------------------------------------------------------------- academic calendar

    /**
     * Semesters the platform follows: the one(s) running now (vu_semesters.status) and the last one(s) before them, so
     * students also see their previous courses. When nothing is marked running (between semesters), the latest
     * semester with registrations stands in as the running one. Each comes with running: true/false.
     */
    public function activeSemesters(): Collection
    {
        if (! $this->has('vu_semesters')) {
            return collect();
        }
        $running = $this->has('vu_semesters', 'status')
            ? $this->db()->table($this->t('vu_semesters'))->where('status', config('vuna_bridge.rules.active_semester_status'))->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all()
            : [];
        $withRegs = fn () => $this->db()->table($this->t('vu_semesters').' as vs')->whereExists(fn ($q) => $q->from($this->t('course_regs').' as r')->selectRaw('1')
            ->whereColumn('r.vu_semester_id', 'vs.id')->when($this->has('course_regs', 'is_course_reg'), fn ($w) => $w->whereIn('r.is_course_reg', config('vuna_bridge.rules.registered_flags') ?: [-1])))
            ->orderByDesc('vs.id');
        if (! $running && $this->has('course_regs', 'vu_semester_id')) {
            $latest = $withRegs()->value('vs.id');
            $running = $latest ? [(int) $latest] : [];
        }
        $previous = [];
        $n = max(0, (int) config('vuna_bridge.rules.previous_semesters'));
        if ($running && $n > 0 && $this->has('course_regs', 'vu_semester_id')) {
            $previous = $withRegs()->where('vs.id', '<', min($running))->limit($n)->pluck('vs.id')->map(fn ($i) => (int) $i)->all();
        }
        $ids = array_values(array_unique(array_merge($running, $previous)));
        if (! $ids) {
            return collect();
        }
        $q = $this->db()->table($this->t('vu_semesters').' as vs')->whereIn('vs.id', $ids)
            ->select(['vs.id', 'vs.academic_session_id', 'vs.semester_id']);
        if ($this->has('academic_sessions')) {
            $q->leftJoin($this->t('academic_sessions').' as a', 'a.id', '=', 'vs.academic_session_id')->addSelect($this->cols('academic_sessions', 'a', ['session' => 'session', 'batch' => 'batch', 'programme_id' => 'programme_id', 'vu_session_id' => 'vu_session_id']));
        }
        if ($this->has('semesters', 'semester')) {
            $q->leftJoin($this->t('semesters').' as sm', 'sm.id', '=', 'vs.semester_id')->addSelect('sm.semester as semester_name');
        }

        $rows = $q->orderBy('vs.id')->get();
        $programmes = $this->has('programmes', 'name') ? $this->db()->table($this->t('programmes'))->pluck('name', 'id') : collect();
        $vuSessions = $this->has('vu_sessions') ? $this->db()->table($this->t('vu_sessions'))->whereIn('id', $rows->pluck('vu_session_id')->filter())->get()->keyBy('id') : collect();

        return $rows->map(function ($r) use ($programmes, $vuSessions, $running) {
            $session = trim((string) ($r->session ?? '')) ?: (isset($r->vu_session_id, $vuSessions[$r->vu_session_id]) ? (string) ($vuSessions[$r->vu_session_id]->session ?? '') : '');
            $semester = trim((string) ($r->semester_name ?? '')) ?: 'Semester '.$r->semester_id;
            $vs = isset($r->vu_session_id) ? ($vuSessions[$r->vu_session_id] ?? null) : null;

            return [
                'id' => (int) $r->id,
                'running' => in_array((int) $r->id, $running, true),
                'academic_session_id' => $r->academic_session_id !== null ? (int) $r->academic_session_id : null,
                'session' => $session ?: 'Session '.$r->academic_session_id,
                'semester' => stripos($semester, 'semester') === false ? $semester.' semester' : $semester,
                'programme' => isset($r->programme_id) ? ($programmes[$r->programme_id] ?? null) : null,
                'batch' => isset($r->batch) ? (string) $r->batch : null,
                'starts_on' => $this->date($vs->start_date ?? null),
                'ends_on' => $this->date($vs->end_date ?? null),
            ];
        })->values();
    }

    /** @return list<int> */
    public function activeSemesterIds(): array
    {
        return $this->activeSemesterIds ??= $this->activeSemesters()->pluck('id')->all();
    }

    // ---------------------------------------------------------------- structure

    public function faculties(): Collection
    {
        return $this->db()->table($this->t('faculties'))->orderBy('id')->get()
            ->map(fn ($f) => ['id' => (int) $f->id, 'name' => trim((string) $f->name), 'code' => $this->code($f->abb ?? null, 'FAC', $f->id)])->values();
    }

    public function departments(): Collection
    {
        return $this->db()->table($this->t('departments'))->orderBy('id')->get()
            ->map(fn ($d) => ['id' => (int) $d->id, 'name' => trim((string) $d->name), 'code' => $this->code($d->abb ?? null, 'DEP', $d->id),
                'faculty_id' => isset($d->faculty_id) ? (int) $d->faculty_id ?: null : null])->values();
    }

    /** Programmes of study (course_studies). */
    public function programmes(): Collection
    {
        return $this->db()->table($this->t('course_studies'))->orderBy('id')->get()
            ->map(fn ($p) => ['id' => (int) $p->id, 'name' => trim((string) $p->name), 'code' => $this->code($p->abb ?? null, 'PRG', $p->id),
                'department_id' => isset($p->department_id) ? (int) $p->department_id ?: null : null,
                'duration_years' => isset($p->duration) && (int) $p->duration > 0 && (int) $p->duration <= 10 ? (int) $p->duration : null])->values();
    }

    public function courses(int $after, int $limit): Collection
    {
        $q = $this->db()->table($this->t('courses').' as c')->where('c.id', '>', $after)->orderBy('c.id')->limit($limit)
            ->select(array_merge(['c.id', 'c.code', 'c.title'], $this->cols('courses', 'c', ['credit_load', 'level', 'department_id', 'course_study_id', 'semester_offered', 'status', 'course_category_id'])));
        $categories = $this->categories();

        return $q->get()->map(fn ($c) => [
            'id' => (int) $c->id, 'code' => $this->courseCode($c->code), 'title' => trim((string) $c->title),
            'units' => isset($c->credit_load) ? max(0, min(30, (int) $c->credit_load)) : null, 'level' => $this->level($c->level ?? null),
            'department_id' => isset($c->department_id) ? (int) $c->department_id ?: null : null, 'programme_id' => isset($c->course_study_id) ? (int) $c->course_study_id ?: null : null,
            'semester_offered' => isset($c->semester_offered) ? (int) $c->semester_offered : null,
            'published' => ! isset($c->status) || (int) $c->status === 1,
            'category' => isset($c->course_category_id) ? ($categories[$c->course_category_id] ?? null) : null,
        ])->values();
    }

    // ---------------------------------------------------------------- students

    /** COALESCE over the studentship columns that exist, defaulting to "normal". */
    private function studentshipExpr(string $alias = 'sa'): string
    {
        $cols = array_values(array_filter(config('vuna_bridge.rules.studentship_columns'), fn ($c) => $this->has('student_academics', $c)));
        $parts = array_map(fn ($c) => $this->wrap("$alias.$c"), $cols);
        $parts[] = (string) (int) config('vuna_bridge.rules.studentship_default');

        return count($parts) === 1 ? $parts[0] : 'COALESCE('.implode(', ', $parts).')';
    }

    /** Join each student to their (latest) academic record. */
    private function studentsBase(): Builder
    {
        $sa = $this->t('student_academics');

        return $this->db()->table($this->t('students').' as s')
            ->join($sa.' as sa', 'sa.student_id', '=', 's.id')
            ->where('sa.id', '=', fn ($q) => $q->from($sa.' as sa2')->selectRaw('MAX(sa2.id)')->whereColumn('sa2.student_id', 's.id'));
    }

    private function onlyActiveStudents(Builder $q): Builder
    {
        $q->whereIn($this->db()->raw($this->studentshipExpr()), config('vuna_bridge.rules.active_studentships') ?: [-1]);
        if ($this->has('students', 'status')) {
            $q->where(fn ($w) => $w->whereNull('s.status')->orWhereIn('s.status', config('vuna_bridge.rules.active_student_statuses') ?: [-1]));
        }

        return $q;
    }

    private function studentSelect(Builder $q): Builder
    {
        return $q->select(array_merge(
            ['s.id'],
            $this->cols('students', 's', ['fname', 'mname', 'lname', 'email', 'phone', 'gender', 'matric_no', 'reg_no', 'username', 'status', 'student_role_id']),
            $this->cols('student_academics', 'sa', ['acad_matric_no' => 'matric_no', 'level', 'course_study_id', 'department_id', 'faculty_id', 'academic_session_id', 'vu_semester_id']),
        ))->selectRaw($this->studentshipExpr().' as studentship_code');
    }

    public function students(int $after, int $limit, bool $activeOnly = true): Collection
    {
        $q = $this->studentSelect($this->studentsBase())->where('s.id', '>', $after)->orderBy('s.id')->limit($limit);
        $activeOnly && $this->onlyActiveStudents($q);

        return $this->formatStudents($q->get());
    }

    public function student(int $id): ?array
    {
        return $this->formatStudents($this->studentSelect($this->studentsBase())->where('s.id', $id)->get())->first();
    }

    /** Is each of these students active, and what is their standing? @return array<int, array{active: bool, status: string}> */
    public function studentStatuses(array $ids): array
    {
        $found = $this->formatStudents($this->studentSelect($this->studentsBase())->whereIn('s.id', $ids)->get())->keyBy('id');

        return collect($ids)->mapWithKeys(fn ($id) => [(int) $id => isset($found[$id]) ? ['active' => $found[$id]['active'], 'status' => $found[$id]['status']] : ['active' => false, 'status' => 'removed']])->all();
    }

    private function formatStudents(Collection $rows): Collection
    {
        $roles = $this->has('student_roles', 'name') ? $this->db()->table($this->t('student_roles'))->pluck('name', 'id') : collect();
        $names = config('vuna_bridge.rules.studentship_names');
        $activeStatuses = config('vuna_bridge.rules.active_student_statuses');

        return $rows->map(function ($r) use ($roles, $names, $activeStatuses) {
            $code = (int) $r->studentship_code;
            $standingOk = ! isset($r->status) || $r->status === null || in_array((int) $r->status, $activeStatuses, true);
            $active = in_array($code, config('vuna_bridge.rules.active_studentships'), true) && $standingOk;
            $department = isset($r->department_id) ? (int) $r->department_id ?: null : null;
            $groups = ['student'];
            if (isset($r->student_role_id) && ($role = $roles[$r->student_role_id] ?? null)) {
                $groups[] = (string) $role;
            }

            return [
                'id' => (int) $r->id,
                'subject' => 'student:'.$r->id,
                'type' => 'student',
                'identifier' => $this->first($r->matric_no ?? null, $r->acad_matric_no ?? null, $r->reg_no ?? null),
                'matric_no' => $this->first($r->matric_no ?? null, $r->acad_matric_no ?? null),
                'reg_no' => $this->first($r->reg_no ?? null),
                'username' => $this->first($r->username ?? null),
                ...$this->names($r->fname ?? null, $r->mname ?? null, $r->lname ?? null),
                'email' => $this->email($r->email ?? null),
                'phone' => $this->first($r->phone ?? null),
                'gender' => $this->first($r->gender ?? null),
                'level' => $this->level($r->level ?? null),
                'programme_id' => isset($r->course_study_id) ? (int) $r->course_study_id ?: null : null,
                'department_id' => $department,
                'faculty_id' => (isset($r->faculty_id) ? (int) $r->faculty_id ?: null : null) ?? $this->facultyOf($department),
                'semester_id' => isset($r->vu_semester_id) ? (int) $r->vu_semester_id ?: null : null,
                'status' => $standingOk ? ($names[$code] ?? 'inactive') : 'suspended',
                'active' => $active,
                'groups' => $groups,
            ];
        })->values();
    }

    // ---------------------------------------------------------------- staff

    private function staffBase(): Builder
    {
        $q = $this->db()->table($this->t('staff').' as st');
        if ($this->has('staff_work_profiles', 'staff_id')) {
            $w = $this->t('staff_work_profiles');
            $q->leftJoin($w.' as w', fn ($j) => $j->on('w.staff_id', '=', 'st.id')
                ->where('w.id', '=', fn ($s) => $s->from($w.' as w2')->selectRaw('MAX(w2.id)')->whereColumn('w2.staff_id', 'st.id')));
        }

        return $q;
    }

    private function onlyActiveStaff(Builder $q, string $idColumn = 'st.id'): Builder
    {
        if ($this->has('appointment_terminations', 'staff_id')) {
            $t = $this->t('appointment_terminations');
            $dated = $this->has('appointment_terminations', 'date_terminated');
            $q->whereNotExists(fn ($s) => $s->from($t.' as at')->selectRaw('1')->whereColumn('at.staff_id', $idColumn)
                ->when($dated, fn ($w) => $w->where(fn ($d) => $d->whereNull('at.date_terminated')->orWhere('at.date_terminated', '<=', now()->toDateString()))));
        }
        $inactive = config('vuna_bridge.rules.inactive_staff_statuses');
        if ($inactive && $this->has('staff', 'status')) {
            if ($idColumn === 'st.id') {
                $q->where(fn ($w) => $w->whereNull('st.status')->orWhereNotIn('st.status', $inactive));
            } else {
                $q->whereExists(fn ($s) => $s->from($this->t('staff').' as stx')->selectRaw('1')->whereColumn('stx.id', $idColumn)
                    ->where(fn ($w) => $w->whereNull('stx.status')->orWhereNotIn('stx.status', $inactive)));
            }
        }

        return $q;
    }

    private function staffSelect(Builder $q): Builder
    {
        $work = $this->has('staff_work_profiles', 'staff_id')
            ? $this->cols('staff_work_profiles', 'w', ['staff_no', 'staff_type_id', 'w_faculty_id' => 'faculty_id', 'w_department_id' => 'department_id', 'staff_position_id'])
            : [];

        return $q->select(array_merge(['st.id'], $this->cols('staff', 'st', ['title', 'fname', 'mname', 'lname', 'email', 'p_email', 'phone', 'gender', 'user_type', 'status']), $work));
    }

    public function staff(int $after, int $limit, bool $activeOnly = true): Collection
    {
        $q = $this->staffSelect($this->staffBase())->where('st.id', '>', $after)->orderBy('st.id')->limit($limit);
        $activeOnly && $this->onlyActiveStaff($q);

        return $this->formatStaff($q->get());
    }

    public function staffMember(int $id): ?array
    {
        return $this->formatStaff($this->staffSelect($this->staffBase())->where('st.id', $id)->get())->first();
    }

    /** @return array<int, array{active: bool, status: string}> */
    public function staffStatuses(array $ids): array
    {
        $active = $this->onlyActiveStaff($this->staffBase()->whereIn('st.id', $ids))->pluck('st.id')->map(fn ($id) => (int) $id)->flip();
        $exists = $this->db()->table($this->t('staff'))->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->flip();

        return collect($ids)->mapWithKeys(fn ($id) => [(int) $id => isset($active[(int) $id]) ? ['active' => true, 'status' => 'active']
            : ['active' => false, 'status' => isset($exists[(int) $id]) ? 'terminated' : 'removed']])->all();
    }

    private function formatStaff(Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }
        $ids = $rows->pluck('id')->all();
        $active = $this->onlyActiveStaff($this->staffBase()->whereIn('st.id', $ids))->pluck('st.id')->map(fn ($id) => (int) $id)->flip();
        $roles = $this->staffRoles($ids);
        $teaching = $this->teachingStaffIds($ids);
        $types = $this->has('staff_types', 'name') ? $this->db()->table($this->t('staff_types'))->pluck('name', 'id') : collect();
        $positions = $this->has('staff_positions', 'name') ? $this->db()->table($this->t('staff_positions'))->pluck('name', 'id') : collect();
        $titles = $this->has('titles', 'title') ? $this->db()->table($this->t('titles'))->pluck('title', 'id') : collect();

        return $rows->map(function ($r) use ($active, $roles, $teaching, $types, $positions, $titles) {
            $type = isset($r->staff_type_id) ? ($types[$r->staff_type_id] ?? null) : null;
            $groups = array_values(array_unique(array_filter(array_merge(
                ['staff'], $type ? [(string) $type] : [], isset($teaching[$r->id]) ? ['teaching'] : [],
                collect($roles[$r->id] ?? [])->flatMap(fn ($x) => [$x['name'], $x['title']])->all(),
            ), fn ($g) => is_string($g) && trim($g) !== '')));
            $title = $this->first($r->title ?? null);
            if ($title !== null && ctype_digit($title)) {
                $title = $titles[(int) $title] ?? null;
            }
            $department = isset($r->w_department_id) ? (int) $r->w_department_id ?: null : null;

            return [
                'id' => (int) $r->id,
                'subject' => 'staff:'.$r->id,
                'type' => 'staff',
                'identifier' => $this->first($r->staff_no ?? null),
                'staff_no' => $this->first($r->staff_no ?? null),
                'title' => $title,
                ...$this->names($r->fname ?? null, $r->mname ?? null, $r->lname ?? null),
                'email' => $this->email($r->email ?? null) ?? $this->email($r->p_email ?? null),
                'phone' => $this->first($r->phone ?? null),
                'gender' => $this->first($r->gender ?? null),
                'staff_type' => $type,
                'position' => isset($r->staff_position_id) ? ($positions[$r->staff_position_id] ?? null) : null,
                'department_id' => $department,
                'faculty_id' => (isset($r->w_faculty_id) ? (int) $r->w_faculty_id ?: null : null) ?? $this->facultyOf($department),
                'roles' => array_values($roles[$r->id] ?? []),
                'teaching' => isset($teaching[$r->id]),
                'status' => isset($active[(int) $r->id]) ? 'active' : 'terminated',
                'active' => isset($active[(int) $r->id]),
                'groups' => $groups,
            ];
        })->values();
    }

    /** Functional roles held now (HOD, Dean, Exam Officer, Level Adviser, ...). @return array<int, list<array>> */
    private function staffRoles(array $staffIds): array
    {
        if (! $this->has('staff_assigned_roles', 'staff_id') || ! $this->has('roles', 'name')) {
            return [];
        }
        $q = $this->db()->table($this->t('staff_assigned_roles').' as sar')->join($this->t('roles').' as r', 'r.id', '=', 'sar.role_id')
            ->whereIn('sar.staff_id', $staffIds)
            ->select(array_merge(['sar.staff_id', 'r.name'], $this->cols('roles', 'r', ['title' => 'fullname']), $this->cols('staff_assigned_roles', 'sar', ['department_id', 'faculty_id', 'level'])));
        $this->has('staff_assigned_roles', 'status') && $q->where('sar.status', 1);
        $this->has('staff_assigned_roles', 'removed_date') && $q->whereNull('sar.removed_date');

        $out = [];
        foreach ($q->get() as $r) {
            $out[(int) $r->staff_id][] = ['name' => trim((string) $r->name), 'title' => isset($r->title) ? trim((string) $r->title) : null,
                'department_id' => isset($r->department_id) ? (int) $r->department_id ?: null : null, 'faculty_id' => isset($r->faculty_id) ? (int) $r->faculty_id ?: null : null,
                'level' => $this->level($r->level ?? null)];
        }

        return $out;
    }

    // ---------------------------------------------------------------- teaching and registrations

    /** Teaching tables that exist and carry what is needed. @return list<string> */
    private function teachingTables(): array
    {
        return array_values(array_filter(config('vuna_bridge.rules.teaching_tables'),
            fn ($t) => $this->has($t, 'staff_id') && $this->has($t, 'course_id') && $this->has($t, 'vu_semester_id')));
    }

    private function teachingStaffIds(array $staffIds): array
    {
        $ids = $this->activeSemesterIds();
        if (! $ids) {
            return [];
        }
        $found = [];
        foreach ($this->teachingTables() as $table) {
            foreach ($this->db()->table($this->t($table))->whereIn('staff_id', $staffIds)->whereIn('vu_semester_id', $ids)->distinct()->pluck('staff_id') as $id) {
                $found[(int) $id] = true;
            }
        }

        return $found;
    }

    /**
     * Courses each active staff member teaches in the running semester(s). Cursor "next" walks the teaching
     * tables one after another ("{table index}:{last id}").
     *
     * @return array{data: Collection, next: ?string}
     */
    public function teaching(?string $cursor, int $limit): array
    {
        $tables = $this->teachingTables();
        $ids = $this->activeSemesterIds();
        [$index, $after] = preg_match('/^(\d+):(\d+)$/', (string) $cursor, $m) ? [(int) $m[1], (int) $m[2]] : [0, 0];
        $data = collect();
        $next = null;
        while ($ids && isset($tables[$index]) && $data->count() < $limit) {
            $table = $tables[$index];
            $want = $limit - $data->count();
            $rows = $this->onlyActiveStaff($this->db()->table($this->t($table).' as x')->join($this->t('courses').' as c', 'c.id', '=', 'x.course_id')
                ->whereIn('x.vu_semester_id', $ids)->where('x.id', '>', $after)->orderBy('x.id')->limit($want)
                ->select(array_merge(['x.id', 'x.staff_id', 'x.course_id', 'x.vu_semester_id', 'c.code', 'c.title'], $this->cols($table, 'x', ['staff_type']))), 'x.staff_id')->get();
            foreach ($rows as $r) {
                $data->push(['id' => "$table:{$r->id}", 'staff_id' => (int) $r->staff_id, 'course_id' => (int) $r->course_id, 'course_code' => $this->courseCode($r->code),
                    'course_title' => trim((string) $r->title), 'semester_id' => (int) $r->vu_semester_id,
                    'role' => isset($r->staff_type) && stripos((string) $r->staff_type, 'coord') !== false ? 'coordinator' : 'lecturer']);
            }
            if ($rows->count() === $want) {
                // page full: carry on in this table next time
                $next = "$index:{$rows->last()->id}";
                break;
            }
            [$index, $after] = [$index + 1, 0];
        }
        // a full page that happened to end exactly at the end of the last table is followed by one empty page — harmless

        return ['data' => $data->values(), 'next' => $next];
    }

    /** Course registrations of active students in the running semester(s). */
    public function registrations(int $after, int $limit, ?int $studentId = null): Collection
    {
        $ids = $this->activeSemesterIds();
        if (! $ids || ! $this->has('course_regs', 'vu_semester_id')) {
            return collect();
        }
        $sa = $this->t('student_academics');
        $q = $this->db()->table($this->t('course_regs').' as r')->join($this->t('courses').' as c', 'c.id', '=', 'r.course_id')
            ->whereIn('r.vu_semester_id', $ids)->where('r.id', '>', $after)->orderBy('r.id')->limit($limit)
            ->select(array_merge(['r.id', 'r.student_id', 'r.course_id', 'r.vu_semester_id', 'c.code', 'c.title'], $this->cols('course_regs', 'r', ['is_carryover', 'level']),
                $this->cols('courses', 'c', ['credit_load', 'course_category_id'])));
        if ($this->has('course_regs', 'is_course_reg')) {
            $q->whereIn('r.is_course_reg', config('vuna_bridge.rules.registered_flags') ?: [-1]);
        }
        $studentId !== null && $q->where('r.student_id', $studentId);
        // only students who are active now
        $q->whereExists(function ($s) use ($sa) {
            $s->from($this->t('students').' as s')->join($sa.' as sa', 'sa.student_id', '=', 's.id')->selectRaw('1')->whereColumn('s.id', 'r.student_id')
                ->where('sa.id', '=', fn ($m) => $m->from($sa.' as sa2')->selectRaw('MAX(sa2.id)')->whereColumn('sa2.student_id', 's.id'));
            $this->onlyActiveStudents($s);
        });
        $categories = $this->categories();

        return $q->get()->map(function ($r) use ($categories) {
            $category = isset($r->course_category_id) ? ($categories[$r->course_category_id] ?? null) : null;

            return [
                'id' => (int) $r->id, 'student_id' => (int) $r->student_id, 'course_id' => (int) $r->course_id, 'course_code' => $this->courseCode($r->code),
                'course_title' => trim((string) $r->title), 'units' => isset($r->credit_load) ? (int) $r->credit_load : null, 'semester_id' => (int) $r->vu_semester_id,
                'level' => $this->level($r->level ?? null),
                'category' => ! empty($r->is_carryover) ? 'carryover' : ($category && stripos($category, 'elect') !== false ? 'elective' : 'core'),
            ];
        })->values();
    }

    // ---------------------------------------------------------------- applicants

    private function applicantsBase(): Builder
    {
        $q = $this->db()->table($this->t('users').' as u')->where('u.user_type', config('vuna_bridge.rules.applicant_user_type'));
        if ($this->has('applicant_bio_data', 'user_id')) {
            $b = $this->t('applicant_bio_data');
            $q->leftJoin($b.' as b', fn ($j) => $j->on('b.user_id', '=', 'u.id')
                ->where('b.id', '=', fn ($s) => $s->from($b.' as b2')->selectRaw('MAX(b2.id)')->whereColumn('b2.user_id', 'u.id')));
            // already a student: they sign in as a student instead
            $this->has('applicant_bio_data', 'student_id') && $q->where(fn ($w) => $w->whereNull('b.student_id')->orWhere('b.student_id', 0));
            if (config('vuna_bridge.rules.applicants_current_session_only') && $this->has('applicant_bio_data', 'vu_session_id') && $this->has('vu_sessions', 'status')) {
                $q->whereIn('b.vu_session_id', fn ($s) => $s->from($this->t('vu_sessions'))->select('id')->where('status', 1));
            }
        }

        return $q;
    }

    private function applicantSelect(Builder $q): Builder
    {
        $bio = $this->has('applicant_bio_data', 'user_id') ? $this->cols('applicant_bio_data', 'b', ['reg_no', 'b_fname' => 'fname', 'b_mname' => 'mname', 'b_lname' => 'lname', 'b_phone' => 'phone_no', 'b_gender' => 'gender', 'course_study_id']) : [];

        return $q->select(array_merge(['u.id'], $this->cols('users', 'u', ['fname', 'mname', 'lname', 'name', 'email']), $bio));
    }

    public function applicants(int $after, int $limit): Collection
    {
        return $this->formatApplicants($this->applicantSelect($this->applicantsBase())->where('u.id', '>', $after)->orderBy('u.id')->limit($limit)->get());
    }

    public function applicant(int $id): ?array
    {
        return $this->formatApplicants($this->applicantSelect($this->applicantsBase())->where('u.id', $id)->get())->first();
    }

    /** @return array<int, array{active: bool, status: string}> */
    public function applicantStatuses(array $ids): array
    {
        $active = $this->applicantsBase()->whereIn('u.id', $ids)->pluck('u.id')->map(fn ($id) => (int) $id)->flip();

        return collect($ids)->mapWithKeys(fn ($id) => [(int) $id => ['active' => isset($active[(int) $id]), 'status' => isset($active[(int) $id]) ? 'active' : 'closed']])->all();
    }

    private function formatApplicants(Collection $rows): Collection
    {
        return $rows->map(function ($r) {
            $names = $this->names($r->fname ?? $r->b_fname ?? null, $r->mname ?? $r->b_mname ?? null, $r->lname ?? $r->b_lname ?? null);
            if ($names['name'] === '' && ! empty($r->name)) {
                $names['name'] = trim((string) $r->name);
            }

            return [
                'id' => (int) $r->id, 'subject' => 'applicant:'.$r->id, 'type' => 'applicant',
                'identifier' => $this->first($r->reg_no ?? null), 'reg_no' => $this->first($r->reg_no ?? null),
                ...$names, 'email' => $this->email($r->email ?? null),
                'phone' => $this->first($r->b_phone ?? null), 'gender' => $this->first($r->b_gender ?? null),
                'programme_id' => isset($r->course_study_id) ? (int) $r->course_study_id ?: null : null,
                'status' => 'active', 'active' => true, 'groups' => ['applicant'],
            ];
        })->values();
    }

    // ---------------------------------------------------------------- sign-in

    /**
     * Find the account a person is signing in to, by the portal table that holds that kind of person.
     * Returns the password hash with the profile so the caller can check it; null when nobody matches.
     *
     * @return array{profile: ?array, hash: ?string}|null
     */
    public function account(string $type, string $login): ?array
    {
        $login = trim($login);
        if ($login === '') {
            return null;
        }
        $email = mb_strtolower($login);

        switch ($type) {
            case 'staff':
                if (! $this->has('staff', 'password')) {
                    return null;
                }
                $q = $this->db()->table($this->t('staff').' as st')->select(['st.id', 'st.password']);
                $q->where(function ($w) use ($email, $login) {
                    $w->whereRaw('LOWER('.$this->wrap('st.email').') = ?', [$email]);
                    if ($this->has('staff_work_profiles', 'staff_no')) {
                        $w->orWhereIn('st.id', fn ($s) => $s->from($this->t('staff_work_profiles'))->select('staff_id')->where('staff_no', $login));
                    }
                });
                $row = $q->orderBy('st.id')->first();

                return $row ? ['profile' => $this->staffMember((int) $row->id), 'hash' => $row->password] : null;

            case 'student':
                if (! $this->has('students', 'password')) {
                    return null;
                }
                $q = $this->db()->table($this->t('students').' as s')->select(['s.id', 's.password']);
                $q->where(function ($w) use ($email, $login) {
                    $w->whereRaw('LOWER('.$this->wrap('s.email').') = ?', [$email]);
                    foreach (['matric_no', 'username', 'reg_no'] as $col) {
                        $this->has('students', $col) && $w->orWhere("s.$col", $login);
                    }
                    if ($this->has('student_academics', 'matric_no')) {
                        $w->orWhereIn('s.id', fn ($s) => $s->from($this->t('student_academics'))->select('student_id')->where('matric_no', $login));
                    }
                });
                $row = $q->orderBy('s.id')->first();

                return $row ? ['profile' => $this->student((int) $row->id), 'hash' => $row->password] : null;

            case 'applicant':
                if (! $this->has('users', 'password')) {
                    return null;
                }
                $row = $this->db()->table($this->t('users').' as u')->where('u.user_type', config('vuna_bridge.rules.applicant_user_type'))
                    ->whereRaw('LOWER('.$this->wrap('u.email').') = ?', [$email])->orderBy('u.id')->first(['u.id', 'u.password']);
                if (! $row) {
                    return null;
                }
                $profile = $this->applicant((int) $row->id);

                return ['profile' => $profile ?? ['id' => (int) $row->id, 'subject' => 'applicant:'.$row->id, 'type' => 'applicant', 'active' => false, 'status' => 'closed'], 'hash' => $row->password];
        }

        return null;
    }

    /** A person by their bridge subject ("staff:12", "student:40", "applicant:7"). */
    public function bySubject(string $subject): ?array
    {
        if (! preg_match('/^(staff|student|applicant):(\d+)$/', $subject, $m)) {
            return null;
        }

        return match ($m[1]) {
            'staff' => $this->staffMember((int) $m[2]),
            'student' => $this->student((int) $m[2]),
            'applicant' => $this->applicant((int) $m[2]),
        };
    }

    /** Numbers the directory reports in its health check. */
    public function counts(): array
    {
        return [
            'active_semesters' => $this->activeSemesters()->where('running', true)->count(),
            'active_students' => $this->onlyActiveStudents($this->studentsBase())->count(),
            'active_staff' => $this->onlyActiveStaff($this->staffBase())->count(),
        ];
    }

    // ---------------------------------------------------------------- helpers

    private function categories(): Collection
    {
        return $this->has('course_categories', 'name') ? $this->db()->table($this->t('course_categories'))->pluck('name', 'id') : collect();
    }

    private function facultyOf(?int $department): ?int
    {
        if (! $department) {
            return null;
        }
        $this->departmentFaculty ??= $this->has('departments', 'faculty_id') ? $this->db()->table($this->t('departments'))->pluck('faculty_id', 'id')->all() : [];

        return isset($this->departmentFaculty[$department]) ? (int) $this->departmentFaculty[$department] ?: null : null;
    }

    /** Levels may be stored as 100/200..., "100L", or as an id into the levels table. */
    public function level(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $n = (int) preg_replace('/\D/', '', (string) $value);
        if ($n > 0 && $n < 10) {
            $this->levels ??= $this->has('levels', 'level') ? $this->db()->table($this->t('levels'))->pluck('level', 'id')->all() : [];
            $n = isset($this->levels[$n]) ? (int) preg_replace('/\D/', '', (string) $this->levels[$n]) : $n * 100;
        }

        return $n >= 100 && $n <= 900 ? $n : null;
    }

    public function courseCode(mixed $code): string
    {
        return mb_substr(mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $code))), 0, 20);
    }

    private function code(mixed $abb, string $prefix, int|string $id): string
    {
        $abb = mb_strtoupper(trim(preg_replace('/\s+/', '', (string) $abb)));

        return mb_substr($abb !== '' ? $abb : $prefix.$id, 0, 20);
    }

    private function names(?string $first, ?string $middle, ?string $last): array
    {
        $f = trim((string) $first);
        $m = trim((string) $middle);
        $l = trim((string) $last);

        return ['first_name' => $f ?: null, 'middle_name' => $m ?: null, 'last_name' => $l ?: null, 'name' => trim(preg_replace('/\s+/', ' ', "$f $m $l"))];
    }

    private function email(mixed $value): ?string
    {
        $e = mb_strtolower(trim((string) $value));

        return $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : null;
    }

    private function first(mixed ...$values): ?string
    {
        foreach ($values as $v) {
            if ($v !== null && trim((string) $v) !== '') {
                return trim((string) $v);
            }
        }

        return null;
    }

    private function date(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
