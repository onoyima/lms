<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\Semester;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Lms\CourseContentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Academic structure (LMS 11-18, 171-173; Target 51-52): faculties, departments,
 * programmes, courses with prerequisites and outcomes, programme course plans,
 * sessions and semesters (activate, close, roll over), offerings with lecturers,
 * capacity and class streams.
 */
class AcademicController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $semester = $request->integer('semester') ?: Semester::where('is_current', true)->value('id');

        return Inertia::render('admin/academics', [
            'tab' => $request->query('tab', 'structure'),
            'faculties' => Faculty::orderBy('name')->get(['id', 'name', 'code']),
            'departments' => Department::orderBy('name')->get(['id', 'name', 'code', 'faculty_id']),
            'programmes' => Programme::orderBy('name')->get(['id', 'name', 'code', 'department_id', 'duration_years']),
            'courses' => Course::orderBy('code')->get(['id', 'code', 'title', 'credit_units', 'level', 'department_id', 'description', 'learning_outcomes', 'active'])
                ->map(fn ($c) => $c->toArray() + ['prerequisites' => DB::table('course_prerequisites')->where('course_id', $c->id)->pluck('prerequisite_id')]),
            'plan' => DB::table('programme_courses')->where('tenant_id', tenant_id())->get(['id', 'programme_id', 'course_id', 'level', 'category']),
            'sessions' => AcademicSession::with(['semesters' => fn ($q) => $q->orderBy('id')])->orderByDesc('id')->get()
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'is_current' => $s->is_current, 'status' => $s->status, 'starts_on' => $s->starts_on, 'ends_on' => $s->ends_on,
                    'semesters' => $s->semesters->map(fn ($m) => $m->only(['id', 'name', 'is_current', 'status', 'starts_on', 'ends_on', 'closed_at']) + [
                        'offerings' => CourseOffering::where('semester_id', $m->id)->count()])]),
            'semester' => $semester,
            'offerings' => $semester ? CourseOffering::with(['course:id,code,title,credit_units', 'staff:id,name'])->where('semester_id', $semester)
                ->withCount(['enrolments as students' => fn ($q) => $q->whereIn('status', ['active', 'completed'])])->get()
                ->map(fn ($o) => ['id' => $o->id, 'course' => $o->course, 'capacity' => $o->capacity, 'status' => $o->status, 'review_status' => $o->review_status, 'students' => $o->students,
                    'staff' => $o->staff->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'role' => $s->pivot->role]),
                    'streams' => DB::table('enrolments')->where('course_offering_id', $o->id)->whereNotNull('stream')->groupBy('stream')->selectRaw('stream, COUNT(*) n')->pluck('n', 'stream')]) : [],
            'staff' => User::where('user_type', 'staff')->where('status', 'active')->orderBy('name')->limit(2000)->get(['id', 'name']),
        ]);
    }

    // ── Faculties, departments, programmes ────────────────────────────────

    public function saveUnit(Request $request, string $kind): RedirectResponse
    {
        [$model, $rules] = match ($kind) {
            'faculty' => [Faculty::class, ['name' => ['required', 'string', 'max:190'], 'code' => ['required', 'string', 'max:20']]],
            'department' => [Department::class, ['name' => ['required', 'string', 'max:190'], 'code' => ['required', 'string', 'max:20'], 'faculty_id' => ['required', 'integer']]],
            'programme' => [Programme::class, ['name' => ['required', 'string', 'max:190'], 'code' => ['required', 'string', 'max:30'], 'department_id' => ['required', 'integer'], 'duration_years' => ['required', 'integer', 'min:1', 'max:8']]],
            default => abort(404),
        };
        $data = $request->validate($rules + ['id' => ['nullable', 'integer']]);
        $data['code'] = strtoupper(trim($data['code']));
        if (isset($data['faculty_id'])) {
            abort_unless(Faculty::whereKey($data['faculty_id'])->exists(), 422);
        }
        if (isset($data['department_id'])) {
            abort_unless(Department::whereKey($data['department_id'])->exists(), 422);
        }
        $table = (new $model)->getTable();
        $request->validate(['code' => [Rule::unique($table)->where('tenant_id', tenant_id())->ignore($data['id'] ?? null)]]);
        $row = isset($data['id']) ? $model::findOrFail($data['id']) : new $model;
        $row->fill(collect($data)->except('id')->all())->save();
        $this->audit->record("academics.$kind.saved", $row, $data);

        return back()->with('success', ucfirst($kind).' saved.');
    }

    public function deleteUnit(Request $request, string $kind, int $id): RedirectResponse
    {
        $model = ['faculty' => Faculty::class, 'department' => Department::class, 'programme' => Programme::class, 'course' => Course::class][$kind] ?? abort(404);
        $row = $model::findOrFail($id);
        // Nothing with history is ever deleted: refuse if anything depends on it
        $inUse = match ($kind) {
            'faculty' => Department::where('faculty_id', $id)->exists(),
            'department' => Programme::where('department_id', $id)->exists() || Course::where('department_id', $id)->exists(),
            'programme' => DB::table('student_profiles')->where('programme_id', $id)->exists(),
            'course' => CourseOffering::where('course_id', $id)->exists() || DB::table('exams')->where('course_id', $id)->exists(),
        };
        if ($inUse) {
            return back()->withErrors(['delete' => 'This is still in use. Deactivate it instead of deleting.']);
        }
        $row->delete();
        $this->audit->record("academics.$kind.deleted", $row);

        return back()->with('success', 'Deleted.');
    }

    // ── Courses, prerequisites, programme plans ───────────────────────────

    public function saveCourse(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer'], 'code' => ['required', 'string', 'max:20'], 'title' => ['required', 'string', 'max:190'],
            'credit_units' => ['required', 'integer', 'min:0', 'max:30'], 'level' => ['nullable', 'integer', 'min:100', 'max:900'],
            'department_id' => ['nullable', 'integer'], 'description' => ['nullable', 'string', 'max:5000'], 'learning_outcomes' => ['nullable', 'string', 'max:4000'],
            'active' => ['boolean'], 'prerequisites' => ['array'], 'prerequisites.*' => ['integer'],
        ]);
        $data['code'] = strtoupper(preg_replace('/\s+/', ' ', trim($data['code'])));
        $request->validate(['code' => [Rule::unique('courses')->where('tenant_id', tenant_id())->ignore($data['id'] ?? null)]]);
        $course = isset($data['id']) ? Course::findOrFail($data['id']) : new Course;
        $course->fill(collect($data)->except(['id', 'prerequisites', 'learning_outcomes', 'active'])->all());
        $course->forceFill(['learning_outcomes' => $data['learning_outcomes'] ?? null, 'active' => $data['active'] ?? true])->save();

        $pre = collect($data['prerequisites'] ?? [])->unique()->reject(fn ($p) => $p === $course->id);
        // No cycles: a course cannot (indirectly) require itself
        foreach ($pre as $p) {
            if ($this->requires($p, $course->id)) {
                return back()->withErrors(['prerequisites' => 'That would make a circle of prerequisites.']);
            }
        }
        DB::table('course_prerequisites')->where('course_id', $course->id)->delete();
        DB::table('course_prerequisites')->insert($pre->filter(fn ($p) => Course::whereKey($p)->exists())->map(fn ($p) => ['course_id' => $course->id, 'prerequisite_id' => $p])->values()->all());
        $this->audit->record('academics.course.saved', $course, $data);

        return back()->with('success', "{$course->code} saved.");
    }

    private function requires(int $course, int $target, int $depth = 0): bool
    {
        if ($course === $target) {
            return true;
        }
        if ($depth > 20) {
            return false;
        }
        foreach (DB::table('course_prerequisites')->where('course_id', $course)->pluck('prerequisite_id') as $p) {
            if ($this->requires($p, $target, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    public function savePlan(Request $request): RedirectResponse
    {
        $data = $request->validate(['programme_id' => ['required', 'integer'], 'rows' => ['present', 'array', 'max:400'],
            'rows.*.course_id' => ['required', 'integer'], 'rows.*.level' => ['required', 'integer', 'min:100', 'max:900'], 'rows.*.category' => ['required', 'in:core,elective']]);
        abort_unless(Programme::whereKey($data['programme_id'])->exists(), 422);
        DB::transaction(function () use ($data) {
            DB::table('programme_courses')->where('programme_id', $data['programme_id'])->delete();
            DB::table('programme_courses')->insert(collect($data['rows'])->unique('course_id')->filter(fn ($r) => Course::whereKey($r['course_id'])->exists())
                ->map(fn ($r) => $r + ['tenant_id' => tenant_id(), 'programme_id' => $data['programme_id']])->values()->all());
        });
        $this->audit->record('academics.plan.saved', Programme::find($data['programme_id']), ['courses' => count($data['rows'])]);

        return back()->with('success', 'Programme courses saved.');
    }

    // ── Sessions and semesters ────────────────────────────────────────────

    public function saveSession(Request $request): RedirectResponse
    {
        $data = $request->validate(['id' => ['nullable', 'integer'], 'name' => ['required', 'string', 'max:30', 'regex:/^\d{4}\/\d{4}$/'], 'starts_on' => ['nullable', 'date'], 'ends_on' => ['nullable', 'date', 'after:starts_on'],
            'semesters' => ['array', 'max:4'], 'semesters.*' => ['string', 'max:40']]);
        $s = isset($data['id']) ? AcademicSession::findOrFail($data['id']) : new AcademicSession;
        $s->fill(collect($data)->only(['name', 'starts_on', 'ends_on'])->all())->save();
        foreach ($data['semesters'] ?? [] as $name) {
            Semester::firstOrCreate(['academic_session_id' => $s->id, 'name' => $name]);
        }
        $this->audit->record('academics.session.saved', $s, $data);

        return back()->with('success', 'Session saved.');
    }

    public function semesterAction(Request $request, Semester $semester): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', 'in:activate,close,rollover,dates'], 'from' => ['required_if:action,rollover', 'integer'], 'copy_content' => ['boolean'],
            'starts_on' => ['nullable', 'date'], 'ends_on' => ['nullable', 'date']]);
        $msg = DB::transaction(function () use ($data, $semester, $request) {
            switch ($data['action']) {
                case 'dates':
                    $semester->update(['starts_on' => $data['starts_on'] ?? null, 'ends_on' => $data['ends_on'] ?? null]);

                    return 'Dates saved.';
                case 'activate':
                    Semester::where('id', '!=', $semester->id)->where('is_current', true)->update(['is_current' => false]);
                    AcademicSession::where('id', '!=', $semester->academic_session_id)->update(['is_current' => false]);
                    $semester->forceFill(['is_current' => true, 'status' => 'active'])->save();
                    AcademicSession::whereKey($semester->academic_session_id)->update(['is_current' => true, 'status' => 'active']);

                    return "{$semester->name} is now the current semester.";
                case 'close':
                    $open = DB::table('course_grades')->join('course_offerings', 'course_offerings.id', '=', 'course_grades.course_offering_id')
                        ->where('course_offerings.semester_id', $semester->id)->where('course_grades.status', '!=', 'released')->count();
                    if ($open && ! $request->boolean('force')) {
                        abort(422, "$open course results are not released yet. Release them first.");
                    }
                    CourseOffering::where('semester_id', $semester->id)->update(['status' => 'archived']);
                    DB::table('registration_windows')->where('semester_id', $semester->id)->update(['closes_at' => now(), 'late_closes_at' => null]);
                    $semester->forceFill(['status' => 'closed', 'closed_at' => now(), 'is_current' => false])->save();

                    return "{$semester->name} closed and its courses archived.";
                case 'rollover':
                    $from = Semester::findOrFail($data['from']);
                    $content = app(CourseContentService::class);
                    $n = 0;
                    foreach (CourseOffering::with('staff')->where('semester_id', $from->id)->get() as $old) {
                        $new = CourseOffering::firstOrCreate(['course_id' => $old->course_id, 'semester_id' => $semester->id], ['status' => 'open', 'capacity' => $old->capacity]);
                        $new->staff()->syncWithoutDetaching($old->staff->mapWithKeys(fn ($s) => [$s->id => ['role' => $s->pivot->role]])->all());
                        if (($data['copy_content'] ?? false) && ! $new->sections()->exists()) {
                            $content->copyFrom($old, $new, $request->user());
                        }
                        $n++;
                    }

                    return "$n courses carried over from {$from->name}".(($data['copy_content'] ?? false) ? ' with their content.' : '.');
            }
        });
        $this->audit->record("academics.semester.{$data['action']}", $semester, $data);

        return back()->with('success', $msg);
    }

    // ── Offerings, lecturers, streams ─────────────────────────────────────

    public function saveOffering(Request $request): RedirectResponse
    {
        $data = $request->validate(['id' => ['nullable', 'integer'], 'course_id' => ['required', 'integer'], 'semester_id' => ['required', 'integer'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'], 'status' => ['required', 'in:draft,open,closed,archived'],
            'staff' => ['array', 'max:20'], 'staff.*.user_id' => ['required', 'integer'], 'staff.*.role' => ['required', 'in:coordinator,lecturer,assistant']]);
        abort_unless(Course::whereKey($data['course_id'])->exists() && Semester::whereKey($data['semester_id'])->exists(), 422);
        $o = isset($data['id']) ? CourseOffering::findOrFail($data['id']) : CourseOffering::firstOrNew(['course_id' => $data['course_id'], 'semester_id' => $data['semester_id']]);
        $o->fill(['capacity' => $data['capacity'] ?? null, 'status' => $data['status']])->save();
        if (isset($data['staff'])) {
            $valid = User::whereIn('id', collect($data['staff'])->pluck('user_id'))->where('user_type', 'staff')->pluck('id')->flip();
            $o->staff()->sync(collect($data['staff'])->filter(fn ($s) => isset($valid[$s['user_id']]))->mapWithKeys(fn ($s) => [$s['user_id'] => ['role' => $s['role']]])->all());
            foreach ($o->staff as $s) {
                \App\Support\Roles::ensureTeacher($s);
            }
        }
        $this->audit->record('academics.offering.saved', $o, $data);

        return back()->with('success', 'Course offering saved.');
    }

    /** Split the class into streams of a given size, alphabetically by name (LMS 18). */
    public function streams(Request $request, CourseOffering $offering): RedirectResponse
    {
        $size = $request->validate(['size' => ['required', 'integer', 'min:5', 'max:2000']])['size'];
        $ids = DB::table('enrolments')->join('users', 'users.id', '=', 'enrolments.user_id')->where('course_offering_id', $offering->id)
            ->whereIn('enrolments.status', ['active', 'pending'])->orderBy('users.name')->pluck('enrolments.id');
        foreach ($ids->chunk($size)->values() as $i => $chunk) {
            DB::table('enrolments')->whereIn('id', $chunk)->update(['stream' => $this->streamName($i)]);
        }
        $this->audit->record('academics.streams', $offering, ['size' => $size]);

        return back()->with('success', 'Students allocated to '.max(1, (int) ceil($ids->count() / $size)).' streams.');
    }

    private function streamName(int $i): string
    {
        $s = '';
        do {
            $s = chr(65 + $i % 26).$s;
            $i = intdiv($i, 26) - 1;
        } while ($i >= 0);

        return $s;
    }

    /**
     * Bulk changes to many courses offered this semester at once (LMS 173): status, capacity,
     * adding a lecturer, or copying content from the same course last time it ran.
     */
    public function bulkOfferings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer'],
            'action' => ['required', 'in:status,capacity,add_lecturer,remove_lecturer,copy_content'],
            'status' => ['required_if:action,status', 'nullable', 'in:draft,open,closed,archived'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'user_id' => ['required_if:action,add_lecturer,remove_lecturer', 'nullable', 'integer'],
            'role' => ['nullable', 'in:coordinator,lecturer,assistant'],
        ]);
        $offerings = CourseOffering::with('semester')->whereIn('id', $data['ids'])->get();
        abort_if($offerings->count() !== count(array_unique($data['ids'])), 404);
        $lecturer = isset($data['user_id']) ? User::where('user_type', 'staff')->findOrFail($data['user_id']) : null;
        $n = 0;
        DB::transaction(function () use ($data, $offerings, $lecturer, $request, &$n) {
            foreach ($offerings as $o) {
                switch ($data['action']) {
                    case 'status': $o->update(['status' => $data['status']]); break;
                    case 'capacity': $o->update(['capacity' => $data['capacity'] ?? null]); break;
                    case 'add_lecturer':
                        $o->staff()->syncWithoutDetaching([$lecturer->id => ['role' => $data['role'] ?? 'lecturer']]);
                        \App\Support\Roles::ensureTeacher($lecturer);
                        break;
                    case 'remove_lecturer': $o->staff()->detach($lecturer->id); break;
                    case 'copy_content':
                        $prev = CourseOffering::where('course_id', $o->course_id)->where('id', '!=', $o->id)->whereHas('sections')
                            ->join('semesters', 'semesters.id', '=', 'course_offerings.semester_id')->orderByDesc('semesters.starts_on')->select('course_offerings.*')->first();
                        if (! $prev || $o->sections()->exists()) {
                            continue 2;
                        }
                        app(CourseContentService::class)->copyFrom($prev, $o, $request->user());
                        break;
                }
                $n++;
            }
        });
        $this->audit->record('academics.offerings.bulk', null, ['action' => $data['action'], 'count' => $n, 'ids' => $data['ids']]);

        return back()->with('success', "Done for $n course(s).".($data['action'] === 'copy_content' && $n < $offerings->count() ? ' Courses that already had content, or never ran before, were left alone.' : ''));
    }

    /** Create or update many courses from a CSV: code, title, credit_units, level, department_code (LMS 173). */
    public function importCourses(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);
        $rows = array_map('str_getcsv', preg_split('/\r\n|\r|\n/', trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $request->file('file')->get()))));
        $head = array_map(fn ($h) => strtolower(trim((string) $h)), array_shift($rows) ?? []);
        abort_unless(in_array('code', $head, true) && in_array('title', $head, true), 422, 'The first row must name the columns: code, title, credit_units, level, department_code.');
        abort_if(count($rows) > 3000, 422, 'At most 3,000 courses per file.');
        $depts = Department::get(['id', 'code'])->mapWithKeys(fn ($d) => [strtoupper((string) $d->code) => $d->id]);
        $made = 0;
        $updated = 0;
        $errors = [];
        DB::transaction(function () use ($rows, $head, $depts, &$made, &$updated, &$errors) {
            foreach ($rows as $i => $cells) {
                if (count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                    continue;
                }
                $r = array_combine($head, array_pad(array_slice($cells, 0, count($head)), count($head), null));
                $code = strtoupper(preg_replace('/\s+/', ' ', trim((string) $r['code'])));
                $title = trim((string) $r['title']);
                $units = (int) ($r['credit_units'] ?? 3);
                $level = isset($r['level']) && $r['level'] !== '' ? (int) $r['level'] : null;
                if ($code === '' || strlen($code) > 20 || $title === '' || $units < 0 || $units > 30 || ($level !== null && ($level < 100 || $level > 900))) {
                    $errors[] = 'Row '.($i + 2).': check the code, title, units (0–30) and level (100–900).';

                    continue;
                }
                $dept = ! empty($r['department_code']) ? ($depts[strtoupper(trim($r['department_code']))] ?? null) : null;
                if (! empty($r['department_code']) && ! $dept) {
                    $errors[] = 'Row '.($i + 2).": no department with code {$r['department_code']}.";

                    continue;
                }
                $c = Course::firstOrNew(['code' => $code]);
                $c->exists ? $updated++ : $made++;
                $c->fill(array_filter(['title' => $title, 'credit_units' => $units, 'level' => $level, 'department_id' => $dept], fn ($v) => $v !== null))->save();
            }
        });
        $this->audit->record('academics.courses.imported', null, ['created' => $made, 'updated' => $updated, 'errors' => count($errors)]);

        return back()->with($errors ? 'error' : 'success', "$made course(s) added, $updated updated.".($errors ? ' '.count($errors).' row(s) skipped: '.implode(' ', array_slice($errors, 0, 5)) : ''));
    }
}
