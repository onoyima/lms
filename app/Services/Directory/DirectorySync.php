<?php

namespace App\Services\Directory;

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\Semester;
use App\Models\SsoIdentity;
use App\Models\SsoProvider;
use App\Models\User;
use App\Services\Academics\SemesterRollover;
use App\Services\Audit\AuditLogger;
use App\Services\Integrations\Connectors;
use App\Services\Sso\SsoLogin;
use App\Support\Roles;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Keeps an institution in step with its records system through the directory bridge:
 * faculties, departments, programmes, courses, the running semester(s), active staff, students and
 * (optionally) applicants, who teaches what, and who is registered for what.
 *
 * - Nothing is ever deleted. People who stop being active are switched off (and switched back on if
 *   they return); registrations and teaching that the portal drops are marked dropped / removed, but
 *   only the ones that came from the portal — anything set by hand on the platform is left alone.
 * - Switching people off only happens after every list was read completely, and stops if it would
 *   switch off an unusual number of accounts at once (a half-empty portal must not lock everyone out).
 * - Every run is recorded in sync_runs and the audit log.
 */
class DirectorySync
{
    private const PERSON_KINDS = ['staff' => 'dir.staff', 'student' => 'dir.student', 'applicant' => 'dir.applicant'];

    private DirectoryClient $client;

    private ?SsoProvider $sso = null;

    /** @var array<string, array<string, int>> legacy_map kind => [portal id => platform id] */
    private array $maps = [];

    /** @var array<string, array<string, int>> people seen in this run: kind => [portal id => user id] */
    private array $seen = ['staff' => [], 'student' => [], 'applicant' => []];

    private array $semesterMap = [];

    /** Platform semester ids the portal says are running now (the others are previous semesters). */
    private array $running = [];

    private array $courseByCode = [];

    private array $offerings = [];

    private array $seenTeaching = [];

    private array $seenEnrolments = [];

    private array $enrolmentsPerSemester = [];

    private array $stats = [];

    /** Platform user ids already tied to a portal person (any kind). */
    private array $linkedUsers = [];

    /** Map changes made inside a row's transaction: applied only once that row is saved. */
    private ?array $pending = null;

    private array $errors = [];

    public function __construct(private Connectors $connectors, private AuditLogger $audit, private SemesterRollover $rollover) {}

    /** What the bridge says right now, without changing anything (for "Test connection"). */
    public function test(): array
    {
        $client = DirectoryClient::fromSettings($this->connectors->get('directory'));
        $term = $client->get('term');
        $health = $client->get('health');

        return ['institution' => $term['institution'] ?? null, 'semesters' => $term['semesters'] ?? [], 'counts' => $health['counts'] ?? []];
    }

    /**
     * Bring one person in (or refresh them) straight from the portal — used when they sign in with their
     * portal details, so a new student can use the platform before the next hourly sync.
     */
    public function upsertOne(array $profile): User
    {
        $kind = $profile['type'] ?? '';
        if (! isset(self::PERSON_KINDS[$kind])) {
            throw new DirectoryException('Unknown kind of portal account.');
        }
        $c = $this->connectors->get('directory');
        $this->sso = ! empty($c['sso_provider_id']) ? SsoProvider::find($c['sso_provider_id']) : null;
        $this->single = true;
        $this->pending = null;
        $this->resetStats();
        $this->maps = [];
        foreach (DB::table('legacy_map')->where('tenant_id', tenant_id())
            ->where(fn ($q) => $q->whereIn('kind', ['dir.faculty', 'dir.department', 'dir.programme'])
                ->orWhere(fn ($w) => $w->where('kind', self::PERSON_KINDS[$kind])->where('legacy_id', (string) $profile['id'])))
            ->get(['kind', 'legacy_id', 'new_id']) as $m) {
            $this->maps[$m->kind][$m->legacy_id] = (int) $m->new_id;
        }
        DB::transaction(fn () => $this->person($kind, $profile));

        return User::findOrFail($this->seen[$kind][(string) $profile['id']]);
    }

    private bool $single = false;

    private function isLinkedUser(int $userId): bool
    {
        return $this->single
            ? DB::table('legacy_map')->where('tenant_id', tenant_id())->whereIn('kind', array_values(self::PERSON_KINDS))->where('new_id', $userId)->exists()
            : isset($this->linkedUsers[$userId]);
    }

    private function resetStats(): void
    {
        $this->stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'received' => 0, 'faculties' => 0, 'departments' => 0, 'programmes' => 0,
            'courses' => 0, 'semesters' => 0, 'staff' => 0, 'students' => 0, 'applicants' => 0, 'teaching' => 0, 'teaching_removed' => 0, 'enrolments' => 0,
            'enrolments_dropped' => 0, 'switched_off' => 0, 'switched_on' => 0, 'kept_suspended' => 0, 'current_semester' => null, 'warnings' => []];
    }

    /** @return array the run summary (also stored in sync_runs) */
    public function run(bool $force = false): array
    {
        $tid = tenant_id() ?? throw new DirectoryException('No institution is selected.');
        $c = $this->connectors->get('directory');
        if (! $c['enabled']) {
            throw new DirectoryException('The directory connection is switched off.');
        }
        $lock = Cache::lock("directory-sync:$tid", 3600);
        if (! $lock->get()) {
            throw new DirectoryException('A directory sync is already running for this institution.');
        }

        $this->resetStats();
        $this->single = false;
        $this->running = [];
        $this->errors = [];
        $started = microtime(true);
        $fatal = null;

        try {
            $this->client = DirectoryClient::fromSettings($c);
            $this->sso = ! empty($c['sso_provider_id']) ? SsoProvider::find($c['sso_provider_id']) : null;
            $this->maps = [];
            foreach (DB::table('legacy_map')->where('tenant_id', $tid)->where('kind', 'like', 'dir.%')->get(['kind', 'legacy_id', 'new_id']) as $m) {
                $this->maps[$m->kind][$m->legacy_id] = (int) $m->new_id;
                in_array($m->kind, self::PERSON_KINDS, true) && $this->linkedUsers[(int) $m->new_id] = true;
            }

            $term = $this->client->get('term');
            $this->structure();
            $this->semesters((array) ($term['semesters'] ?? []));
            $this->people('staff', 'staff');
            $this->people('student', 'students');
            if ($c['include_applicants']) {
                $this->people('applicant', 'applicants');
            }
            $this->teaching();
            $this->registrations();
            $this->cleanUp();
            if ($c['follow_current_semester']) {
                $this->currentSemester();
            }
            if ($c['deactivate_missing']) {
                foreach (['staff', 'student'] + ($c['include_applicants'] ? [2 => 'applicant'] : []) as $kind) {
                    $this->switchOffMissing($kind, $force);
                }
            }
        } catch (DirectoryException $e) {
            $fatal = $e->getMessage();
        } catch (\Throwable $e) {
            report($e);
            $fatal = 'The sync stopped unexpectedly: '.mb_substr($e->getMessage(), 0, 200);
        } finally {
            $lock->release();
        }

        $summary = $this->stats + ['ok' => $fatal === null, 'error' => $fatal, 'seconds' => round(microtime(true) - $started, 1)];
        DB::table('sync_runs')->insert(['tenant_id' => $tid, 'api_key_id' => null, 'kind' => 'directory', 'idempotency_key' => null,
            'received' => $this->stats['received'], 'created' => $this->stats['created'], 'updated' => $this->stats['updated'],
            'failed' => $this->stats['failed'] + ($fatal ? 1 : 0), 'errors' => json_encode(array_slice(array_merge($fatal ? [['id' => null, 'message' => $fatal]] : [], $this->errors), 0, 200)),
            'response' => json_encode($summary), 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('integration.directory_sync', null, array_intersect_key($summary, array_flip(['ok', 'error', 'created', 'updated', 'failed', 'switched_off', 'switched_on', 'enrolments', 'enrolments_dropped', 'current_semester'])));

        return $summary;
    }

    // ---------------------------------------------------------------- structure

    private function structure(): void
    {
        foreach ((array) $this->client->get('faculties')['data'] as $f) {
            $this->guarded("faculty {$f['id']}", function () use ($f) {
                $model = $this->linked('dir.faculty', $f['id'], Faculty::class) ?? $this->adopt(Faculty::class, $f['code'], 'dir.faculty');
                $model ??= new Faculty(['code' => $this->uniqueCode(Faculty::class, $f['code'], null, 20)]);
                $model->fill(['name' => $f['name'] ?: $model->name ?: $f['code'], 'external_id' => 'portal:'.$f['id']])->save();
                $this->link('dir.faculty', $f['id'], $model->id);
                $this->stats['faculties']++;
            });
        }
        foreach ((array) $this->client->get('departments')['data'] as $d) {
            $this->guarded("department {$d['id']}", function () use ($d) {
                $faculty = $this->maps['dir.faculty'][$d['faculty_id'] ?? ''] ?? null;
                $model = $this->linked('dir.department', $d['id'], Department::class) ?? $this->adopt(Department::class, $d['code'], 'dir.department');
                if (! $faculty && ! $model?->faculty_id) {
                    throw new \RuntimeException("Department {$d['name']} has no faculty in the portal.");
                }
                $model ??= new Department(['code' => $this->uniqueCode(Department::class, $d['code'], null, 20)]);
                $model->fill(['name' => $d['name'] ?: $model->name ?: $d['code'], 'faculty_id' => $faculty ?? $model->faculty_id, 'external_id' => 'portal:'.$d['id']])->save();
                $this->link('dir.department', $d['id'], $model->id);
                $this->stats['departments']++;
            });
        }
        foreach ((array) $this->client->get('programmes')['data'] as $p) {
            $this->guarded("programme {$p['id']}", function () use ($p) {
                $department = $this->maps['dir.department'][$p['department_id'] ?? ''] ?? null;
                $model = $this->linked('dir.programme', $p['id'], Programme::class) ?? $this->adopt(Programme::class, $p['code'], 'dir.programme');
                if (! $department && ! $model?->department_id) {
                    throw new \RuntimeException("Programme {$p['name']} has no department in the portal.");
                }
                $model ??= new Programme(['code' => $this->uniqueCode(Programme::class, $p['code'], null, 30)]);
                $model->fill(['name' => $p['name'] ?: $model->name ?: $p['code'], 'department_id' => $department ?? $model->department_id,
                    'duration_years' => $p['duration_years'] ?? $model->duration_years ?? 4, 'external_id' => 'portal:'.$p['id']])->save();
                $this->link('dir.programme', $p['id'], $model->id);
                $this->stats['programmes']++;
            });
        }
        $this->client->each('courses', function (array $rows) {
            DB::transaction(function () use ($rows) {
                foreach ($rows as $row) {
                    $this->guarded("course {$row['id']}", function () use ($row) {
                        if (trim((string) $row['code']) === '') {
                            return;
                        }
                        $course = $this->course($row['code'], $row['title'] ?? null, $row);
                        $this->link('dir.course', $row['id'], $course->id);
                    });
                }
            });
        });
    }

    /** One platform course per course code (the portal can list the same code under several programmes). */
    private function course(string $code, ?string $title, array $row = []): Course
    {
        $code = mb_substr(mb_strtoupper(trim(preg_replace('/\s+/', ' ', $code))), 0, 20);
        if (isset($this->courseByCode[$code])) {
            $course = Course::find($this->courseByCode[$code]);
            if ($course) {
                return $course;
            }
        }
        $course = Course::firstOrNew(['code' => $code]);
        $new = ! $course->exists;
        $course->fill([
            'title' => trim((string) $title) !== '' ? trim((string) $title) : ($course->title ?: $code),
            'credit_units' => isset($row['units']) ? (int) $row['units'] : ($course->credit_units ?? 2),
            'level' => $row['level'] ?? $course->level,
            'department_id' => $this->maps['dir.department'][$row['department_id'] ?? ''] ?? $course->department_id,
            'external_id' => $course->external_id ?: $code,
        ]);
        if ($new || $course->isDirty()) {
            $course->save();
            $this->stats['courses']++;
        }

        return Course::find($this->courseByCode[$code] = $course->id);
    }

    private function semesters(array $semesters): void
    {
        foreach ($semesters as $s) {
            $this->guarded("semester {$s['id']}", function () use ($s) {
                $session = AcademicSession::firstOrCreate(['name' => mb_substr((string) $s['session'], 0, 60)], ['starts_on' => $s['starts_on'] ?? null, 'ends_on' => $s['ends_on'] ?? null]);
                if ((! $session->starts_on && ! empty($s['starts_on'])) || (! $session->ends_on && ! empty($s['ends_on']))) {
                    $session->fill(['starts_on' => $session->starts_on ?? $s['starts_on'], 'ends_on' => $session->ends_on ?? $s['ends_on']])->save();
                }
                $name = ucfirst(mb_substr((string) $s['semester'], 0, 60));
                $semester = $this->linked('dir.semester', $s['id'], Semester::class)
                    ?? Semester::firstOrCreate(['academic_session_id' => $session->id, 'name' => $name], ['starts_on' => $s['starts_on'] ?? null, 'ends_on' => $s['ends_on'] ?? null]);
                if (($semester->status ?? null) === 'planned') {
                    $semester->forceFill(['status' => 'active'])->save();
                }
                $this->link('dir.semester', $s['id'], $semester->id);
                $this->semesterMap[(int) $s['id']] = $semester->id;
                if ($s['running'] ?? true) {
                    $this->running[$semester->id] = true;
                }
                $this->stats['semesters']++;
            });
        }
    }

    // ---------------------------------------------------------------- people

    private function people(string $kind, string $path): void
    {
        $this->client->each($path, function (array $rows) use ($kind) {
            DB::transaction(function () use ($rows, $kind) {
                foreach ($rows as $row) {
                    $this->stats['received']++;
                    $this->guarded("$kind {$row['id']}", fn () => $this->person($kind, $row));
                }
            });
        });
    }

    private function person(string $kind, array $row): void
    {
        $mapKind = self::PERSON_KINDS[$kind];
        $type = $kind === 'staff' ? 'staff' : 'student';
        $identifier = isset($row['identifier']) && trim((string) $row['identifier']) !== '' ? mb_substr(trim((string) $row['identifier']), 0, 60) : null;
        $email = isset($row['email']) && filter_var($row['email'], FILTER_VALIDATE_EMAIL) ? mb_strtolower((string) $row['email']) : null;

        $user = $this->linked($mapKind, $row['id'], User::class);
        if (! $user) {
            // an account made by hand or by an earlier import: same matric/staff number, else same e-mail
            $candidate = ($identifier ? User::where('identifier', $identifier)->first() : null) ?? ($email ? User::where('email', $email)->first() : null);
            if ($candidate) {
                if ($candidate->isPlatformAdmin() || $this->isLinkedUser($candidate->id) || (($candidate->user_type === 'staff') !== ($type === 'staff'))) {
                    throw new \RuntimeException(($identifier ?? $email).' already belongs to another account here ('.$candidate->user_type.'); fix it under People.');
                }
                $user = $candidate;
            }
        }

        $placeholder = "$kind-{$row['id']}@portal.invalid";
        if ($email && User::where('email', $email)->when($user, fn ($q) => $q->where('id', '!=', $user->id))->exists()) {
            $this->warn("The e-mail of $kind {$row['id']} is used by another account; kept the current address.");
            $email = null;
        }
        if ($identifier && User::where('identifier', $identifier)->when($user, fn ($q) => $q->where('id', '!=', $user->id))->exists()) {
            throw new \RuntimeException("$identifier is already used by another account here.");
        }
        $name = mb_substr(trim((string) ($row['name'] ?? '')) ?: ($identifier ?? $email ?? "Portal $kind {$row['id']}"), 0, 190);

        if (! $user) {
            $user = User::forceCreate([
                'name' => $name, 'email' => $email ?? $placeholder, 'identifier' => $identifier, 'user_type' => $type, 'status' => 'active',
                'password' => Hash::make(Str::password(40)), 'email_verified_at' => $email ? now() : null,
            ]);
            $this->stats['created']++;
        } else {
            $user->forceFill(['name' => $name]);
            $user->forceFill(array_filter(['email' => $email ?? (str_ends_with($user->email, '.invalid') ? $placeholder : null), 'identifier' => $identifier, 'user_type' => $type], fn ($v) => $v !== null));
            if ($user->status !== 'active') {
                if ($this->isSwitchedOffByDirectory($user->id)) {
                    $user->forceFill(['status' => 'active']);
                    DB::table('legacy_map')->where('tenant_id', tenant_id())->where('kind', 'dir.off')->where('legacy_id', (string) $user->id)->delete();
                    $this->stats['switched_on']++;
                } else {
                    $this->stats['kept_suspended']++; // suspended on the platform by a person: stays suspended
                }
            }
            if ($user->isDirty()) {
                $user->save();
                $this->stats['updated']++;
            } else {
                $this->stats['unchanged']++;
            }
        }
        $this->link($mapKind, $row['id'], $user->id);
        $this->remember(fn () => $this->seen[$kind][(string) $row['id']] = $user->id);
        $this->stats[$kind === 'staff' ? 'staff' : ($kind === 'student' ? 'students' : 'applicants')]++;

        if ($kind === 'staff') {
            DB::table('staff_profiles')->upsert([[
                'tenant_id' => tenant_id(), 'user_id' => $user->id,
                'faculty_id' => $this->maps['dir.faculty'][$row['faculty_id'] ?? ''] ?? null, 'department_id' => $this->maps['dir.department'][$row['department_id'] ?? ''] ?? null,
                'title' => isset($row['title']) ? mb_substr((string) $row['title'], 0, 40) : null,
                'designation' => mb_substr((string) ($row['position'] ?? $row['staff_type'] ?? ''), 0, 80) ?: null,
                'created_at' => now(), 'updated_at' => now(),
            ]], ['user_id'], ['faculty_id', 'department_id', 'title', 'designation', 'updated_at']);
            if (! empty($row['teaching'])) {
                Roles::ensureTeacher($user);
            }
        } else {
            DB::table('student_profiles')->upsert([[
                'tenant_id' => tenant_id(), 'user_id' => $user->id, 'programme_id' => $this->maps['dir.programme'][$row['programme_id'] ?? ''] ?? null,
                'level' => $row['level'] ?? null, 'academic_status' => $kind === 'applicant' ? 'applicant' : 'active', 'external_id' => $identifier,
                'synced_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]], ['user_id'], ['programme_id', 'level', 'academic_status', 'external_id', 'synced_at', 'updated_at']);
            if ($kind === 'student') {
                Roles::ensureLearner($user);
            } elseif (! $user->hasRole('applicant')) {
                $user->assignRole(Roles::ensureRole('applicant', 'Applicant', 'Applicants from the university portal: they can sit the screening exams they are added to.'));
            }
        }

        if ($this->sso) {
            // sign-in through the portal finds this account even without an e-mail address
            SsoIdentity::updateOrCreate(['sso_provider_id' => $this->sso->id, 'subject' => (string) $row['subject']], ['user_id' => $user->id]);
            SsoLogin::syncRuleRoles($this->sso, $user, ['groups' => $row['groups'] ?? [], 'user_type' => $row['type'] ?? $kind, 'status' => $row['status'] ?? null]);
        }
    }

    // ---------------------------------------------------------------- teaching and registrations

    private function offering(int $courseId, int $semesterId): int
    {
        return $this->offerings["$courseId:$semesterId"] ??= CourseOffering::firstOrCreate(['course_id' => $courseId, 'semester_id' => $semesterId], ['status' => 'open'])->id;
    }

    private function teaching(): void
    {
        $this->client->each('teaching', function (array $rows) {
            DB::transaction(function () use ($rows) {
                foreach ($rows as $t) {
                    $this->guarded("teaching {$t['id']}", function () use ($t) {
                        $user = $this->seen['staff'][(string) $t['staff_id']] ?? null;
                        $semester = $this->semesterMap[(int) $t['semester_id']] ?? null;
                        if (! $user || ! $semester) {
                            return; // not an active staff member here, or a semester we do not follow
                        }
                        $offering = $this->offering($this->course($t['course_code'], $t['course_title'] ?? null)->id, $semester);
                        $existing = DB::table('course_offering_staff')->where('course_offering_id', $offering)->where('user_id', $user)->first();
                        if (! $existing) {
                            DB::table('course_offering_staff')->insert(['course_offering_id' => $offering, 'user_id' => $user, 'role' => $t['role'] === 'coordinator' ? 'coordinator' : 'lecturer',
                                'source' => 'directory', 'created_at' => now(), 'updated_at' => now()]);
                        } elseif ($existing->source === 'directory' && $existing->role !== $t['role']) {
                            DB::table('course_offering_staff')->where('id', $existing->id)->update(['role' => $t['role'] === 'coordinator' ? 'coordinator' : 'lecturer', 'updated_at' => now()]);
                        }
                        $this->seenTeaching["$offering:$user"] = true;
                        $this->stats['teaching']++;
                        Roles::ensureTeacher(User::find($user));
                    });
                }
            });
        });
    }

    private function registrations(): void
    {
        $this->client->each('registrations', function (array $rows) {
            DB::transaction(function () use ($rows) {
                foreach ($rows as $r) {
                    $this->guarded("registration {$r['id']}", function () use ($r) {
                        $user = $this->seen['student'][(string) $r['student_id']] ?? null;
                        $semester = $this->semesterMap[(int) $r['semester_id']] ?? null;
                        if (! $user || ! $semester) {
                            return;
                        }
                        $offering = $this->offering($this->course($r['course_code'], $r['course_title'] ?? null, ['units' => $r['units'] ?? null])->id, $semester);
                        $category = in_array($r['category'] ?? null, ['core', 'elective', 'carryover'], true) ? $r['category'] : 'core';
                        // the running semester's courses are current; earlier semesters' show as previous courses
                        DB::table('enrolments')->upsert([['tenant_id' => tenant_id(), 'course_offering_id' => $offering, 'user_id' => $user, 'category' => $category,
                            'status' => isset($this->running[$semester]) ? 'active' : 'completed', 'source' => 'directory', 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()]],
                            ['course_offering_id', 'user_id'], ['category', 'status', 'source', 'updated_at']);
                        $this->seenEnrolments["$offering:$user"] = true;
                        if (isset($this->running[$semester])) {
                            $this->enrolmentsPerSemester[$semester] = ($this->enrolmentsPerSemester[$semester] ?? 0) + 1;
                        }
                        $this->stats['enrolments']++;
                    });
                }
            });
        });
    }

    /** Registrations and teaching the portal no longer has, in the semesters we follow — only ones that came from the portal. */
    private function cleanUp(): void
    {
        $semesters = array_values(array_unique($this->semesterMap));
        if (! $semesters) {
            return;
        }
        $offerings = DB::table('course_offerings')->whereIn('semester_id', $semesters)->pluck('id');
        foreach ($offerings->chunk(500) as $chunk) {
            foreach (DB::table('course_offering_staff')->whereIn('course_offering_id', $chunk)->where('source', 'directory')->get(['id', 'course_offering_id', 'user_id']) as $row) {
                if (! isset($this->seenTeaching["{$row->course_offering_id}:{$row->user_id}"])) {
                    DB::table('course_offering_staff')->where('id', $row->id)->delete();
                    $this->stats['teaching_removed']++;
                }
            }
            foreach (DB::table('enrolments')->where('tenant_id', tenant_id())->whereIn('course_offering_id', $chunk)->where('source', 'directory')->where('status', 'active')->get(['id', 'course_offering_id', 'user_id']) as $row) {
                if (! isset($this->seenEnrolments["{$row->course_offering_id}:{$row->user_id}"])) {
                    DB::table('enrolments')->where('id', $row->id)->update(['status' => 'dropped', 'updated_at' => now()]);
                    $this->stats['enrolments_dropped']++;
                }
            }
        }
    }

    /** The semester the portal is running (the one most students are registered in) becomes the current one. */
    private function currentSemester(): void
    {
        $semesters = array_keys($this->running) ?: array_values(array_unique($this->semesterMap));
        if (! $semesters) {
            return;
        }
        arsort($this->enrolmentsPerSemester);
        $primary = array_key_first($this->enrolmentsPerSemester) ?? $semesters[0];
        $semester = Semester::find($primary);
        if ($semester && ! $semester->is_current) {
            $this->rollover->activate($semester);
        }
        $this->stats['current_semester'] = $semester?->name;
    }

    /** People this institution got from the portal who are no longer in its active lists. */
    private function switchOffMissing(string $kind, bool $force): void
    {
        $mapKind = self::PERSON_KINDS[$kind];
        $linked = $this->maps[$mapKind] ?? [];
        $seen = $this->seen[$kind];
        $missing = array_diff_key($linked, $seen);
        if (! $missing) {
            return;
        }
        $active = User::whereIn('id', array_values($missing))->where('status', 'active')->pluck('id')->flip();
        $missing = array_filter($missing, fn ($userId) => isset($active[$userId]));
        if (! $missing) {
            return;
        }
        if (! $force && ($seen === [] || count($missing) > max(20, (int) ceil(count($linked) * 0.25)))) {
            $this->warn(sprintf('Did not switch off %d %s accounts: that is more than expected at once. Check the portal, then use "Sync now" with "switch off anyway".', count($missing), $kind));

            return;
        }
        foreach (array_chunk(array_keys($missing), 500, true) as $chunk) {
            $status = (array) ($this->client->get('statuses', ['type' => $kind, 'ids' => implode(',', $chunk)])['data'] ?? []);
            foreach ($chunk as $portalId) {
                $st = $status[(string) $portalId] ?? ['active' => false, 'status' => 'removed'];
                if (! empty($st['active'])) {
                    continue; // came back while we were reading
                }
                $user = User::find($missing[$portalId]);
                if (! $user || $user->status !== 'active') {
                    continue;
                }
                $new = in_array($st['status'] ?? '', ['deferred', 'withdrawn', 'suspended', 'graduated'], true) ? $st['status'] : 'inactive';
                DB::transaction(function () use ($user, $new, $kind) {
                    $user->forceFill(['status' => $new])->save();
                    $this->link('dir.off', $user->id, $user->id);
                    if ($kind !== 'staff') {
                        DB::table('student_profiles')->where('user_id', $user->id)->update(['academic_status' => $kind === 'applicant' ? 'closed' : ($new === 'inactive' ? 'withdrawn' : $new), 'updated_at' => now()]);
                    }
                    DB::table('sessions')->where('user_id', $user->id)->delete(); // signed out everywhere
                });
                $this->stats['switched_off']++;
            }
        }
    }

    // ---------------------------------------------------------------- helpers

    private function isSwitchedOffByDirectory(int $userId): bool
    {
        return DB::table('legacy_map')->where('tenant_id', tenant_id())->where('kind', 'dir.off')->where('legacy_id', (string) $userId)->exists();
    }

    private function linked(string $kind, int|string $portalId, string $model): mixed
    {
        $id = $this->maps[$kind][(string) $portalId] ?? null;

        return $id ? $model::find($id) : null;
    }

    /** An existing record with the same code that no portal record has claimed yet. */
    private function adopt(string $model, string $code, string $kind): mixed
    {
        $found = $model::where('code', $code)->first();

        return $found && ! in_array($found->id, array_values($this->maps[$kind] ?? []), true) ? $found : null;
    }

    private function uniqueCode(string $model, string $code, ?int $selfId, int $max): string
    {
        $code = mb_substr($code, 0, $max);
        $try = $code;
        for ($n = 2; $model::where('code', $try)->when($selfId, fn ($q) => $q->where('id', '!=', $selfId))->exists(); $n++) {
            $try = mb_substr($code, 0, $max - strlen("-$n"))."-$n";
        }

        return $try;
    }

    private function link(string $kind, int|string $portalId, int $id): void
    {
        if (($this->maps[$kind][(string) $portalId] ?? null) === $id) {
            return;
        }
        DB::table('legacy_map')->upsert([['tenant_id' => tenant_id(), 'kind' => $kind, 'legacy_id' => (string) $portalId, 'new_id' => $id, 'imported_at' => now()]],
            ['tenant_id', 'kind', 'legacy_id'], ['new_id', 'imported_at']);
        $this->remember(function () use ($kind, $portalId, $id) {
            $this->maps[$kind][(string) $portalId] = $id;
            in_array($kind, self::PERSON_KINDS, true) && $this->linkedUsers[$id] = true;
        });
    }

    /** Change the in-memory maps now, or — inside a row's transaction — once that row has been saved. */
    private function remember(callable $change): void
    {
        if ($this->pending === null) {
            $change();
        } else {
            $this->pending[] = $change;
        }
    }

    private function guarded(string $what, callable $work): void
    {
        $this->pending = [];
        try {
            DB::transaction(fn () => $work());
            foreach ($this->pending as $change) {
                $change();
            }
        } catch (DirectoryException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // the row was rolled back: forget anything cached from it
            $this->offerings = [];
            $this->courseByCode = [];
            $this->stats['failed']++;
            if (count($this->errors) < 200) {
                $this->errors[] = ['id' => $what, 'message' => $e instanceof \RuntimeException ? $e->getMessage() : 'Could not save this row.'];
            }
            $e instanceof \RuntimeException || report($e);
        } finally {
            $this->pending = null;
        }
    }

    private function warn(string $message): void
    {
        $this->stats['warnings'][] = $message;
    }
}
