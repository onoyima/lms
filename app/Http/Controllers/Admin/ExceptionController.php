<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CourseOffering;
use App\Models\Exam;
use App\Models\ExamCandidate;
use App\Models\ExceptionRequest;
use App\Models\HardwareException;
use App\Models\User;
use App\Notifications\LmsNotice;
use App\Services\Audit\AuditLogger;
use App\Support\Exams\ExamSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Formal exceptions: students ask (missed exam, deadline, accessibility, hardware…) and staff decide
 * with a recorded reason (LMS 178); and the register of hardware exceptions that switch individual
 * proctoring checks off for one candidate, approved by a second person (Target 141).
 */
class ExceptionController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    private function canDecide(User $u): bool
    {
        return $u->can('exams.manage') || $u->can('academics.manage') || $u->can('results.approve');
    }

    /* ---------- the student's side ---------- */

    public function mine(Request $request): Response
    {
        $u = $request->user();

        return Inertia::render('learn/exceptions', [
            'requests' => ExceptionRequest::where('user_id', $u->id)->with('offering.course:id,code', 'exam:id,title')->latest('id')->get()
                ->map(fn ($r) => $r->only(['id', 'type', 'subject', 'reason', 'status', 'decision_note', 'decided_at', 'created_at', 'evidence_name']) + ['course' => $r->offering?->course?->code, 'exam' => $r->exam?->title]),
            'types' => ExceptionRequest::TYPES,
            'courses' => CourseOffering::whereHas('enrolments', fn ($q) => $q->where('user_id', $u->id))->with('course:id,code,title')->latest('id')->limit(60)->get()
                ->map(fn ($o) => ['id' => $o->id, 'label' => $o->course->code.' '.$o->course->title]),
            'exams' => Exam::whereIn('id', ExamCandidate::where('user_id', $u->id)->join('exam_sessions', 'exam_sessions.id', '=', 'exam_candidates.exam_session_id')->pluck('exam_sessions.exam_id'))
                ->latest('id')->limit(60)->get(['id', 'title']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $u = $request->user();
        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', array_keys(ExceptionRequest::TYPES))],
            'subject' => ['required', 'string', 'max:190'], 'reason' => ['required', 'string', 'min:20', 'max:5000'],
            'course_offering_id' => ['nullable', 'integer'], 'exam_id' => ['nullable', 'integer'],
            'evidence' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
        ]);
        // only the student's own courses and exams can be named
        if ($data['course_offering_id'] ?? null) {
            abort_unless(CourseOffering::whereKey($data['course_offering_id'])->whereHas('enrolments', fn ($q) => $q->where('user_id', $u->id))->exists(), 422, 'Choose one of your courses.');
        }
        if ($data['exam_id'] ?? null) {
            abort_unless(ExamCandidate::where('user_id', $u->id)->whereHas('session', fn ($q) => $q->where('exam_id', $data['exam_id']))->exists(), 422, 'Choose one of your exams.');
        }
        abort_if(ExceptionRequest::where('user_id', $u->id)->where('status', 'pending')->count() >= 10, 429, 'You have 10 requests waiting. Wait for a decision before sending more.');
        $req = new ExceptionRequest(collect($data)->except('evidence')->all() + ['user_id' => $u->id, 'status' => 'pending']);
        if ($file = $request->file('evidence')) {
            $path = 'exceptions/'.tenant_id().'/'.Str::random(40);
            Storage::disk('local')->put($path, Crypt::encryptString(base64_encode($file->get())));
            $req->evidence_path = $path;
            $req->evidence_name = Str::limit(preg_replace('/[^\w. -]/u', '', $file->getClientOriginalName()), 120, '');
        }
        $req->save();
        $this->audit->record('exception.requested', $req, ['type' => $req->type]);
        $deciders = User::permission(['exams.manage'])->where('status', 'active')->limit(20)->get();
        Notification::send($deciders, new LmsNotice('New exception request', "{$u->name}: {$req->subject}", '/admin/exceptions', category: 'requests'));

        return back()->with('success', 'Request sent. You will be notified of the decision.');
    }

    public function withdraw(Request $request, ExceptionRequest $exception): RedirectResponse
    {
        abort_unless($exception->user_id === $request->user()->id && $exception->status === 'pending', 403);
        $exception->update(['status' => 'withdrawn']);

        return back()->with('success', 'Request withdrawn.');
    }

    /** Evidence is seen only by the student and the people who decide. */
    public function evidence(Request $request, ExceptionRequest $exception)
    {
        abort_unless($exception->user_id === $request->user()->id || $this->canDecide($request->user()), 403);
        abort_unless($exception->evidence_path && Storage::disk('local')->exists($exception->evidence_path), 404);
        if ($exception->user_id !== $request->user()->id) {
            $this->audit->record('exception.evidence_viewed', $exception);
        }
        $bytes = base64_decode(Crypt::decryptString(Storage::disk('local')->get($exception->evidence_path)));

        return response($bytes, 200, ['Content-Type' => 'application/octet-stream', 'Content-Disposition' => 'attachment; filename="'.($exception->evidence_name ?: 'evidence').'"',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    /* ---------- the staff side ---------- */

    public function index(Request $request): Response
    {
        abort_unless($this->canDecide($request->user()) || $request->user()->can('users.manage'), 403);
        $status = $request->query('status', 'pending');

        return Inertia::render('admin/exceptions', [
            'requests' => ExceptionRequest::with('user:id,name,identifier', 'offering.course:id,code', 'exam:id,title', 'decider:id,name')
                ->when($status !== 'all', fn ($q) => $q->where('status', $status))->latest('id')->limit(300)->get()
                ->map(fn ($r) => $r->only(['id', 'type', 'subject', 'reason', 'status', 'decision_note', 'decided_at', 'created_at', 'evidence_name'])
                    + ['student' => $r->user?->only(['name', 'identifier']), 'course' => $r->offering?->course?->code, 'exam' => $r->exam?->title, 'decider' => $r->decider?->name]),
            'hardware' => HardwareException::with('user:id,name,identifier', 'exam:id,title')->latest('id')->limit(300)->get()
                ->map(fn ($h) => $h->only(['id', 'kind', 'detail', 'relax', 'status', 'valid_until', 'requested_by', 'decided_at']) + ['student' => $h->user?->only(['name', 'identifier']), 'exam' => $h->exam?->title]),
            'types' => ExceptionRequest::TYPES,
            'kinds' => HardwareException::KINDS,
            'relaxable' => ExamSettings::RELAXABLE,
            'exams' => Exam::whereIn('status', ['draft', 'submitted', 'approved', 'scheduled', 'live'])->latest('id')->limit(100)->get(['id', 'title']),
            'canDecide' => $this->canDecide($request->user()),
            'me' => $request->user()->id,
            'filter' => $status,
        ]);
    }

    public function decide(Request $request, ExceptionRequest $exception): RedirectResponse
    {
        abort_unless($this->canDecide($request->user()), 403);
        abort_unless($exception->status === 'pending', 409, 'This request has already been decided.');
        abort_if($exception->user_id === $request->user()->id, 403, 'You cannot decide your own request.');
        $data = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['required', 'string', 'min:5', 'max:2000']]);
        $exception->update(['status' => $data['approve'] ? 'approved' : 'rejected', 'decided_by' => $request->user()->id, 'decided_at' => now(), 'decision_note' => $data['note']]);
        $this->audit->record('exception.decided', $exception, ['approve' => $data['approve']]);
        $exception->user?->notify(new LmsNotice('Your request: '.($data['approve'] ? 'approved' : 'not approved'), $exception->subject.' — '.$data['note'], '/my/exceptions', category: 'requests'));

        return back()->with('success', 'Decision recorded and the student told.');
    }

    public function storeHardware(Request $request): RedirectResponse
    {
        abort_unless($this->canDecide($request->user()) || $request->user()->can('users.manage'), 403);
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:60'], 'exam_id' => ['nullable', 'integer', 'exists:exams,id'],
            'kind' => ['required', 'in:'.implode(',', HardwareException::KINDS)], 'detail' => ['required', 'string', 'min:10', 'max:2000'],
            'relax' => ['required', 'array', 'min:1'], 'relax.*' => ['in:'.implode(',', ExamSettings::RELAXABLE)], 'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
        ]);
        $student = User::where('identifier', $data['identifier'])->orWhere('email', strtolower($data['identifier']))->first();
        abort_unless($student, 422, 'No one with that matriculation number or email.');
        $h = HardwareException::create(['user_id' => $student->id, 'exam_id' => $data['exam_id'] ?? null, 'kind' => $data['kind'], 'detail' => $data['detail'],
            'relax' => array_values(array_unique($data['relax'])), 'valid_until' => $data['valid_until'] ?? null, 'status' => 'pending', 'requested_by' => $request->user()->id]);
        $this->audit->record('hardware_exception.requested', $h, ['student' => $student->id, 'relax' => $h->relax]);

        return back()->with('success', 'Hardware exception recorded. A second person must approve it before it applies.');
    }

    public function decideHardware(Request $request, HardwareException $hardware): RedirectResponse
    {
        abort_unless($this->canDecide($request->user()), 403);
        $data = $request->validate(['action' => ['required', 'in:approve,reject,revoke']]);
        if ($data['action'] === 'revoke') {
            abort_unless($hardware->status === 'approved', 409);
        } else {
            abort_unless($hardware->status === 'pending', 409, 'Already decided.');
            // two people: whoever recorded it cannot approve it
            abort_if($hardware->requested_by === $request->user()->id, 403, 'Someone else must approve an exception you recorded.');
        }
        $hardware->update(['status' => ['approve' => 'approved', 'reject' => 'rejected', 'revoke' => 'revoked'][$data['action']], 'decided_by' => $request->user()->id, 'decided_at' => now()]);
        $this->audit->record('hardware_exception.'.$hardware->status, $hardware);

        return back()->with('success', 'Hardware exception '.$hardware->status.'.');
    }
}
