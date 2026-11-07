<?php

namespace App\Http\Controllers\Proctor;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\IncidentNote;
use App\Models\ProctorAssignment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Incident queue with severity, assignment, escalation, human review of AI alerts (Target 32-33, 40, 65, 99, 151-153). */
class IncidentController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $incidents = $this->visible($request)->with(['attempt.user:id,name,identifier', 'session.exam:id,title', 'assignee:id,name'])
            ->when($request->string('status')->toString() ?: null, fn ($q, $s) => $q->where('status', $s), fn ($q) => $q->whereIn('status', ['open', 'investigating', 'escalated']))
            ->when($request->boolean('review'), fn ($q) => $q->where('needs_human_review', true))
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->latest('id')->paginate(30)->withQueryString()
            ->through(fn (Incident $i) => [
                'id' => $i->id, 'type' => $i->type, 'severity' => $i->severity, 'status' => $i->status, 'source' => $i->source,
                'needs_review' => $i->needs_human_review, 'title' => $i->title, 'exam' => $i->session?->exam?->title,
                'candidate' => $i->attempt?->user?->name, 'identifier' => $i->attempt?->user?->identifier,
                'assignee' => $i->assignee?->name, 'created_at' => $i->created_at->toDateTimeString(), 'attempt_id' => $i->attempt_id,
            ]);

        return Inertia::render('proctor/incidents', ['incidents' => $incidents, 'filters' => $request->only(['status', 'review'])]);
    }

    /** The exams office sees every incident; proctors see those of sittings they work or assigned to them. */
    private function visible(Request $request)
    {
        $user = $request->user();

        return Incident::query()->when(! $user->can('exams.manage'), fn ($q) => $q->where(fn ($w) => $w->where('assigned_to', $user->id)
            ->orWhereIn('exam_session_id', ProctorAssignment::where('user_id', $user->id)->select('exam_session_id'))));
    }

    private function guard(Request $request, Incident $incident): void
    {
        abort_unless($this->visible($request)->whereKey($incident->id)->exists(), 403);
    }

    public function show(Request $request, Incident $incident): Response
    {
        $this->guard($request, $incident);
        $incident->load(['attempt.user:id,name,identifier', 'session.exam:id,title', 'notes.user:id,name', 'assignee:id,name', 'evidence']);

        return Inertia::render('proctor/incident', [
            'incident' => [
                ...$incident->only(['id', 'type', 'severity', 'status', 'source', 'needs_human_review', 'title', 'description', 'resolution', 'attempt_id']),
                'exam' => $incident->session?->exam?->title, 'candidate' => $incident->attempt?->user?->only(['name', 'identifier']),
                'assignee' => $incident->assignee?->only(['id', 'name']), 'created_at' => $incident->created_at->toDateTimeString(),
                'notes' => $incident->notes->map(fn ($n) => ['body' => $n->body, 'user' => $n->user?->name, 'at' => $n->created_at?->toDateTimeString()]),
                'evidence' => $incident->evidence->map(fn ($e) => $e->only(['id', 'kind', 'mime', 'sha256'])),
            ],
            'contacts' => \App\Support\Policy::forCampus($incident->session?->venue?->campus)['incident_contacts'] ?? [],
            'staff' => User::where('user_type', 'staff')->orderBy('name')->limit(500)->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'exam_session_id' => ['nullable', 'integer'], 'attempt_id' => ['nullable', 'integer'],
            'type' => ['required', 'in:malpractice,technical,power_outage,network,identity,disruption,other'],
            'severity' => ['required', 'in:low,medium,high,critical'], 'title' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:10000'],
        ]);
        // the attempt decides the sitting; staff who are not the exams office may only report on sittings they work
        if (! empty($data['attempt_id'])) {
            $data['exam_session_id'] = \App\Models\Attempt::findOrFail($data['attempt_id'])->exam_session_id;
        }
        if (! empty($data['exam_session_id'])) {
            \App\Models\ExamSession::findOrFail($data['exam_session_id']);
        }
        abort_unless($request->user()->can('exams.manage') || (! empty($data['exam_session_id'])
            && ProctorAssignment::where('exam_session_id', $data['exam_session_id'])->where('user_id', $request->user()->id)->exists()), 403);
        $incident = Incident::create($data + ['status' => 'open', 'source' => 'proctor', 'opened_by' => $request->user()->id]);
        $this->audit->record('incident.opened', $incident, ['severity' => $incident->severity]);
        $session = $incident->exam_session_id ? \App\Models\ExamSession::find($incident->exam_session_id) : ($incident->attempt_id ? \App\Models\Attempt::find($incident->attempt_id)?->session : null);
        $session && app(\App\Services\Proctoring\TrainingSandbox::class)->record($session, $request->user()->id, 'incident');

        return back()->with('success', 'Incident recorded.');
    }

    public function update(Request $request, Incident $incident): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:assign,escalate,investigate,resolve,dismiss,confirm_alert,severity'],
            'user_id' => ['nullable', 'integer'], 'severity' => ['nullable', 'in:low,medium,high,critical'],
            'note' => ['nullable', 'string', 'max:10000'],
        ]);
        $this->guard($request, $incident);
        $user = $request->user();

        switch ($data['action']) {
            case 'assign':
                $incident->update(['assigned_to' => $data['user_id'], 'status' => 'investigating']);
                break;
            case 'escalate': // to the sitting's chief/supervisor, or exams office (Target 152)
                $chief = ProctorAssignment::where('exam_session_id', $incident->exam_session_id)->whereIn('role', ['chief', 'supervisor'])->value('user_id');
                $incident->update(['status' => 'escalated', 'assigned_to' => $data['user_id'] ?? $chief ?? $incident->assigned_to,
                    'severity' => $incident->severity === 'critical' ? 'critical' : ($incident->severity === 'high' ? 'critical' : 'high')]);
                break;
            case 'investigate':
                $incident->update(['status' => 'investigating']);
                break;
            case 'resolve':
            case 'dismiss':
                abort_if(blank($data['note']), 422, 'Write what was decided.');
                $incident->update(['status' => $data['action'] === 'resolve' ? 'resolved' : 'dismissed', 'resolution' => $data['note'], 'resolved_at' => now(), 'reviewed_by' => $user->id, 'needs_human_review' => false]);
                break;
            case 'confirm_alert': // a person confirms an AI alert is real (Target 40)
                $incident->update(['needs_human_review' => false, 'reviewed_by' => $user->id, 'status' => 'investigating', 'type' => 'malpractice']);
                break;
            case 'severity':
                $incident->update(['severity' => $data['severity']]);
                break;
        }
        if (! blank($data['note']) && ! in_array($data['action'], ['resolve', 'dismiss'], true)) {
            IncidentNote::create(['incident_id' => $incident->id, 'user_id' => $user->id, 'body' => $data['note'], 'created_at' => now()]);
        }
        $this->audit->record('incident.'.$data['action'], $incident, array_filter(['note' => $data['note'] ?? null, 'to' => $data['user_id'] ?? null]));

        return back();
    }

    public function note(Request $request, Incident $incident): RedirectResponse
    {
        $this->guard($request, $incident);
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);
        IncidentNote::create(['incident_id' => $incident->id, 'user_id' => $request->user()->id, 'body' => $data['body'], 'created_at' => now()]);

        return back();
    }
}
