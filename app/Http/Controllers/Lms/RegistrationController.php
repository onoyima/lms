<?php

namespace App\Http\Controllers\Lms;

use App\Http\Controllers\Controller;
use App\Models\CourseOffering;
use App\Models\Enrolment;
use App\Models\RegistrationWindow;
use App\Models\Semester;
use App\Models\User;
use App\Services\Lms\RegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Course registration for students, and window/approval/override tools for Registry and HODs (LMS 15-25). */
class RegistrationController extends Controller
{
    public function __construct(private RegistrationService $registration) {}

    private function semester(Request $request): ?Semester
    {
        return $request->integer('semester') ? Semester::find($request->integer('semester')) : Semester::where('is_current', true)->first() ?? Semester::latest('id')->first();
    }

    public function show(Request $request): Response
    {
        $user = $request->user();
        $semester = $this->semester($request);
        $window = $semester ? $this->registration->window($semester) : null;
        $catalogue = $semester ? $this->registration->catalogue($user, $semester) : collect();

        return Inertia::render('learn/registration', [
            'semester' => $semester ? ['id' => $semester->id, 'label' => trim(($semester->academicSession?->name ?? '').' '.$semester->name)] : null,
            'semesters' => Semester::with('academicSession')->latest('id')->limit(12)->get()->map(fn ($s) => ['id' => $s->id, 'label' => trim(($s->academicSession?->name ?? '').' '.$s->name)]),
            'window' => $window ? $window->only(['opens_at', 'closes_at', 'late_closes_at', 'min_units', 'max_units', 'requires_approval']) + ['state' => $this->registration->windowState($window)] : ['state' => 'none'],
            'profile' => $user->studentProfile ? ['programme' => $user->studentProfile->programme?->name, 'level' => $user->studentProfile->level, 'status' => $user->studentProfile->academic_status] : null,
            'catalogue' => $catalogue,
            'isStudent' => $user->user_type === 'student',
            'canManage' => $user->can('academics.manage') || $user->can('users.manage'),
            'pending' => $user->can('academics.manage') || $user->can('results.approve') ? Enrolment::with('user:id,name,identifier', 'offering.course')
                ->where('status', 'pending')->whereHas('offering', fn ($q) => $q->where('semester_id', $semester?->id))->limit(200)->get()
                ->map(fn ($e) => ['id' => $e->id, 'student' => $e->user?->name, 'identifier' => $e->user?->identifier, 'course' => $e->offering->course->code, 'category' => $e->category]) : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $semester = $this->semester($request);
        abort_unless($semester, 404);
        $data = $request->validate(['courses' => ['present', 'array', 'max:20'], 'courses.*' => ['integer']]);
        $r = $this->registration->register($request->user(), $semester, $data['courses']);

        return back()->with('success', "Registered for {$r['units']} units".($r['status'] === 'pending' ? ', awaiting approval.' : '.'));
    }

    /** Registry / HOD registers a student outside the normal rules, with a reason (LMS 24). */
    public function override(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('academics.manage'), 403);
        $semester = $this->semester($request);
        $data = $request->validate(['identifier' => ['required', 'string'], 'courses' => ['present', 'array'], 'courses.*' => ['integer'], 'reason' => ['required', 'string', 'max:300']]);
        $student = User::where('identifier', $data['identifier'])->firstOrFail();
        $this->registration->register($student, $semester, $data['courses'], $request->user(), $data['reason']);

        return back()->with('success', "Registration updated for {$student->name}.");
    }

    public function decide(Request $request, Enrolment $enrolment): RedirectResponse
    {
        abort_unless($request->user()->can('academics.manage') || $request->user()->can('results.approve'), 403);
        $data = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:255']]);
        $this->registration->approve($enrolment, $request->user(), $data['approve'], $data['note'] ?? null);

        return back();
    }

    public function window(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('academics.manage'), 403);
        $data = $request->validate(['semester_id' => ['required', 'integer', 'exists:semesters,id'], 'opens_at' => ['required', 'date'], 'closes_at' => ['required', 'date', 'after:opens_at'],
            'late_closes_at' => ['nullable', 'date', 'after:closes_at'], 'min_units' => ['required', 'integer', 'min:0', 'max:60'], 'max_units' => ['required', 'integer', 'gte:min_units', 'max:60'],
            'requires_approval' => ['boolean']]);
        RegistrationWindow::updateOrCreate(['semester_id' => $data['semester_id']], $data);

        return back()->with('success', 'Registration window saved.');
    }

    /** Printable registration slip (LMS 23). */
    public function slip(Request $request): Response
    {
        $user = $request->user();
        $semester = $this->semester($request);
        $rows = Enrolment::with('offering.course')->where('user_id', $user->id)->whereIn('status', ['active', 'pending'])
            ->whereHas('offering', fn ($q) => $q->where('semester_id', $semester?->id))->get()
            ->map(fn ($e) => ['code' => $e->offering->course->code, 'title' => $e->offering->course->title, 'units' => $e->offering->course->credit_units, 'category' => $e->category, 'status' => $e->status]);

        return Inertia::render('learn/slip', ['student' => ['name' => $user->name, 'identifier' => $user->identifier, 'programme' => $user->studentProfile?->programme?->name, 'level' => $user->studentProfile?->level],
            'semester' => trim(($semester?->academicSession?->name ?? '').' '.$semester?->name), 'rows' => $rows, 'generated_at' => now(),
            'reference' => strtoupper(substr(hash_hmac('sha256', $user->id.'|'.$semester?->id.'|'.$rows->pluck('code')->implode(','), config('app.key')), 0, 12))]);
    }

    /** Class list for lecturers / exams office (LMS 23, 80). */
    public function classList(Request $request, CourseOffering $offering)
    {
        abort_unless(app(\App\Services\Lms\LmsAccess::class)->teaches($request->user(), $offering), 403);

        return response()->streamDownload(function () use ($offering) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Matric/ID', 'Name', 'Email', 'Category', 'Status']);
            DB::table('enrolments')->join('users', 'users.id', '=', 'enrolments.user_id')->where('course_offering_id', $offering->id)->whereIn('enrolments.status', ['active', 'pending', 'completed'])
                ->orderBy('users.name')->select('users.identifier', 'users.name', 'users.email', 'enrolments.category', 'enrolments.status')->chunk(1000, function ($rows) use ($out) {
                    foreach ($rows as $r) {
                        fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, (array) $r));
                    }
                });
            fclose($out);
        }, 'class-list-'.preg_replace('/[^A-Za-z0-9]+/', '-', $offering->label()).'.csv', ['Content-Type' => 'text/csv']);
    }
}
