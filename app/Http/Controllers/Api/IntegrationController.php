<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Programme;
use App\Models\Semester;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Push API for the student information system and other university systems
 * (Target 50, 100, 199, 210-212; LMS 5-6, 10, 215-217, 219, 225-226).
 *
 * Every call is idempotent (send the same Idempotency-Key and you get the first answer back),
 * validates each row on its own, applies the good rows, and returns per-row errors so the
 * sending system can reconcile. Every run is logged in sync_runs.
 */
class IntegrationController extends Controller
{
    public function __construct(private TenantContext $tenant, private AuditLogger $audit) {}

    /** students: [{matric_no, name, email?, programme_code?, level?, status, entry_year?}] (max 1000 per call) */
    public function students(Request $request): JsonResponse
    {
        return $this->run($request, 'students', [
            'matric_no' => ['required', 'string', 'max:50'], 'name' => ['required', 'string', 'max:190'], 'email' => ['nullable', 'email', 'max:190'],
            'programme_code' => ['nullable', 'string', 'max:30'], 'level' => ['nullable', 'integer', 'min:100', 'max:900'],
            'status' => ['required', 'in:active,suspended,withdrawn,graduated,deferred'], 'entry_year' => ['nullable', 'integer'],
        ], function (array $row) {
            $tid = $this->tenant->id();
            $programme = $row['programme_code'] ?? null ? Programme::where('code', $row['programme_code'])->value('id') : null;
            if (($row['programme_code'] ?? null) && ! $programme) {
                throw new \RuntimeException("Unknown programme {$row['programme_code']}");
            }
            $user = DB::table('users')->where('tenant_id', $tid)->where('identifier', $row['matric_no'])->first();
            $email = strtolower($row['email'] ?? Str::slug($row['matric_no'], '.').'@students.invalid');
            if (! empty($row['email']) && ! \App\Support\Policy::emailAllowed($email)) {
                throw new \RuntimeException("$email is not a university address");
            }
            // Students who leave lose access; returning students get it back (LMS 6, 10)
            $access = in_array($row['status'], ['active', 'deferred'], true) ? 'active' : 'inactive';
            // subscription capacity: a new or returning active student needs a free seat
            if ($access === 'active' && (! $user || $user->status !== 'active' || $user->user_type !== 'student')) {
                app(\App\Services\Billing\Entitlements::class)->check('students');
            }
            if ($user) {
                DB::table('users')->where('id', $user->id)->update(['name' => $row['name'], 'email' => $email, 'status' => $access, 'updated_at' => now()]);
                $id = $user->id;
            } else {
                $id = DB::table('users')->insertGetId(['tenant_id' => $tid, 'name' => $row['name'], 'email' => $email, 'identifier' => $row['matric_no'], 'user_type' => 'student',
                    'status' => $access, 'password' => Hash::make(Str::password(32)), 'created_at' => now(), 'updated_at' => now()]);
                $role = DB::table('roles')->where('tenant_id', $tid)->where('name', 'student')->value('id');
                DB::table('model_has_roles')->insertOrIgnore(['role_id' => $role, 'model_type' => \App\Models\User::class, 'model_id' => $id, 'tenant_id' => $tid]);
            }
            DB::table('student_profiles')->upsert([['tenant_id' => $tid, 'user_id' => $id, 'programme_id' => $programme, 'level' => $row['level'] ?? null,
                'academic_status' => $row['status'], 'entry_year' => $row['entry_year'] ?? null, 'external_id' => $row['matric_no'], 'synced_at' => now(),
                'created_at' => now(), 'updated_at' => now()]], ['user_id'], ['programme_id', 'level', 'academic_status', 'entry_year', 'synced_at', 'updated_at']);

            return $user ? 'updated' : 'created';
        });
    }

    /** courses: [{code, title, units, level?, department_code?, description?}] */
    public function courses(Request $request): JsonResponse
    {
        return $this->run($request, 'courses', [
            'code' => ['required', 'string', 'max:20'], 'title' => ['required', 'string', 'max:190'], 'units' => ['required', 'integer', 'min:0', 'max:30'],
            'level' => ['nullable', 'integer'], 'department_code' => ['nullable', 'string', 'max:20'], 'description' => ['nullable', 'string', 'max:5000'],
        ], function (array $row) {
            $course = Course::firstOrNew(['code' => $row['code']]);
            $new = ! $course->exists;
            $course->fill(['title' => $row['title'], 'credit_units' => $row['units'], 'level' => $row['level'] ?? null, 'description' => $row['description'] ?? $course->description,
                'department_id' => ($row['department_code'] ?? null) ? Department::where('code', $row['department_code'])->value('id') : $course->department_id, 'external_id' => $row['code']])->save();

            return $new ? 'created' : 'updated';
        });
    }

    /** registrations: [{matric_no, course_code, semester_id, category?, status: active|dropped}] — approved registrations from the SIS (LMS 21-22, 217) */
    public function registrations(Request $request): JsonResponse
    {
        return $this->run($request, 'registrations', [
            'matric_no' => ['required', 'string'], 'course_code' => ['required', 'string'], 'semester_id' => ['required', 'integer'],
            'category' => ['nullable', 'in:core,elective,carryover'], 'status' => ['required', 'in:active,dropped'],
        ], function (array $row) {
            $user = DB::table('users')->where('tenant_id', $this->tenant->id())->where('identifier', $row['matric_no'])->value('id') ?? throw new \RuntimeException('Unknown student');
            $course = Course::where('code', $row['course_code'])->value('id') ?? throw new \RuntimeException('Unknown course');
            Semester::findOrFail($row['semester_id']);
            $offering = CourseOffering::firstOrCreate(['course_id' => $course, 'semester_id' => $row['semester_id']], ['status' => 'open']);
            $exists = DB::table('enrolments')->where('course_offering_id', $offering->id)->where('user_id', $user)->exists();
            DB::table('enrolments')->upsert([['tenant_id' => $this->tenant->id(), 'course_offering_id' => $offering->id, 'user_id' => $user, 'category' => $row['category'] ?? 'core',
                'status' => $row['status'], 'source' => 'sis', 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()]],
                ['course_offering_id', 'user_id'], ['category', 'status', 'source', 'approved_at', 'updated_at']);

            return $exists ? 'updated' : 'created';
        });
    }

    /** Published exam results and released course grades, for the results system (Target 158; LMS 158, 219). Cursor-paginated. */
    public function results(Request $request): JsonResponse
    {
        $after = (int) $request->query('after', 0);
        $rows = DB::table('course_grades')->join('users', 'users.id', '=', 'course_grades.user_id')
            ->join('course_offerings', 'course_offerings.id', '=', 'course_grades.course_offering_id')->join('courses', 'courses.id', '=', 'course_offerings.course_id')
            ->where('course_grades.tenant_id', $this->tenant->id())->where('course_grades.status', 'released')->where('course_grades.id', '>', $after)
            ->orderBy('course_grades.id')->limit(500)
            ->get(['course_grades.id', 'users.identifier as matric_no', 'courses.code as course_code', 'course_offerings.semester_id', 'courses.credit_units as units',
                'course_grades.total', 'course_grades.grade', 'course_grades.released_at']);
        $this->audit->record('integration.results_export', null, ['after' => $after, 'count' => $rows->count(), 'key' => $request->attributes->get('api_key_id')]);

        return response()->json(['data' => $rows, 'next' => $rows->count() === 500 ? $rows->last()->id : null]);
    }

    public function runs(Request $request): JsonResponse
    {
        return response()->json(DB::table('sync_runs')->where('tenant_id', $this->tenant->id())->orderByDesc('id')->limit(50)
            ->get(['id', 'kind', 'idempotency_key', 'received', 'created', 'updated', 'failed', 'errors', 'created_at']));
    }

    private function run(Request $request, string $kind, array $rules, callable $apply): JsonResponse
    {
        $rows = $request->validate(['data' => ['required', 'array', 'min:1', 'max:1000']])['data'];
        $idem = substr((string) $request->header('Idempotency-Key'), 0, 100) ?: null;
        if ($idem && ($done = DB::table('sync_runs')->where('tenant_id', $this->tenant->id())->where('kind', $kind)->where('idempotency_key', $idem)->value('response'))) {
            return response()->json(json_decode($done, true) + ['replayed' => true]);
        }

        $result = ['received' => count($rows), 'created' => 0, 'updated' => 0, 'failed' => 0, 'errors' => []];
        foreach ($rows as $i => $row) {
            $v = Validator::make(is_array($row) ? $row : [], $rules);
            if ($v->fails()) {
                $result['failed']++;
                $result['errors'][] = ['row' => $i, 'id' => $row['matric_no'] ?? $row['code'] ?? null, 'message' => $v->errors()->first()];

                continue;
            }
            try {
                DB::transaction(function () use ($apply, $v, &$result) {
                    $result[$apply($v->validated())]++;
                });
            } catch (\Throwable $e) {
                $result['failed']++;
                $result['errors'][] = ['row' => $i, 'id' => $row['matric_no'] ?? $row['code'] ?? null, 'message' => $e instanceof \RuntimeException ? $e->getMessage() : 'Could not save this row.'];
                if (! $e instanceof \RuntimeException) {
                    report($e);
                }
            }
        }
        $result['errors'] = array_slice($result['errors'], 0, 500);
        DB::table('sync_runs')->insert(['tenant_id' => $this->tenant->id(), 'api_key_id' => $request->attributes->get('api_key_id'), 'kind' => $kind, 'idempotency_key' => $idem,
            'received' => $result['received'], 'created' => $result['created'], 'updated' => $result['updated'], 'failed' => $result['failed'],
            'errors' => json_encode($result['errors']), 'response' => json_encode($result), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json($result, $result['failed'] && ! $result['created'] && ! $result['updated'] ? 422 : 200);
    }
}
