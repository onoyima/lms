<?php

namespace App\Http\Controllers\Proctor;

use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Models\CandidateMessage;
use App\Models\EvidenceFile;
use App\Models\ExamCandidate;
use App\Models\ExamSession;
use App\Models\Incident;
use App\Models\ProctorAssignment;
use App\Models\ProctoringEvent;
use App\Services\Audit\AuditLogger;
use App\Services\Evidence\EvidenceStore;
use App\Services\Exams\AttemptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Live proctor / invigilator console (Target 6, 29-33, 42-43, 77-79, 97, 105, 148-155).
 * The live view is one compact JSON poll (a few grouped queries), cheap enough for many
 * proctors refreshing every few seconds.
 */
class ProctorController extends Controller
{
    public function __construct(private AttemptService $attempts, private AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $officer = $user->can('exams.manage');
        $mine = $officer ? null : \App\Support\Exams\ExamAccess::scope(\App\Models\Exam::query(), $user)->pluck('exams.id');
        abort_unless($officer || $user->canAny(['exams.proctor', 'exams.invigilate']) || $mine->isNotEmpty(), 403);
        $sessions = ExamSession::with('exam:id,title,type', 'venue:id,name')
            ->when(! $officer, fn ($q) => $q->where(fn ($w) => $w->whereHas('proctors', fn ($p) => $p->where('user_id', $user->id))->orWhereIn('exam_id', $mine)))
            ->where('ends_at', '>=', now()->subDay())->orderBy('starts_at')->limit(100)->get()
            ->map(fn ($s) => [
                'id' => $s->id, 'exam' => $s->exam->title, 'name' => $s->name, 'venue' => $s->venue?->name, 'mode' => $s->mode,
                'status' => $s->paused_at ? 'paused' : $s->status, 'starts_at' => $s->starts_at->toIso8601String(), 'ends_at' => $s->ends_at->toIso8601String(),
            ]);

        return Inertia::render('proctor/index', ['sessions' => $sessions]);
    }

    public function console(Request $request, ExamSession $session): Response
    {
        $this->authorizeSession($request, $session);
        $session->load('exam:id,title,code,type,proctoring', 'venue:id,name,campus');

        return Inertia::render('proctor/console', [
            'session' => [
                'id' => $session->id, 'exam' => $session->exam->title, 'name' => $session->name, 'mode' => $session->mode,
                'venue' => $session->venue?->name, 'starts_at' => $session->starts_at->toIso8601String(), 'ends_at' => $session->ends_at->toIso8601String(),
                'proctoring' => $session->exam->proctoring,
            ],
            'canManage' => $request->user()->can('exams.manage'),
            'isChief' => $this->isChief($request, $session),
            'contacts' => \App\Support\Policy::forCampus($session->venue?->campus)['incident_contacts'] ?? [],
            'training' => $session->is_practice && $session->exam->code === 'PROCTOR-TRAINING' ? [
                'tasks' => \App\Services\Proctoring\TrainingSandbox::TASKS,
                'done' => json_decode(DB::table('proctor_trainings')->where('exam_session_id', $session->id)->where('user_id', $request->user()->id)->value('tasks') ?? '[]', true),
            ] : null,
            'layout' => array_replace(self::LAYOUT, (array) data_get($request->user()->preferences, 'proctor_layout', [])),
        ]);
    }

    public function startTraining(Request $request, \App\Services\Proctoring\TrainingSandbox $sandbox): RedirectResponse
    {
        abort_unless($request->user()->canAny(['exams.proctor', 'exams.invigilate', 'exams.manage']), 403);
        $session = $sandbox->open($request->user());
        $this->audit->record('proctor.training_started', $session);

        return redirect()->route('proctor.console', $session)->with('success', 'Training sandbox ready. Answer the help request, warn a candidate, unlock the locked one and open an incident.');
    }

    public function endTraining(Request $request, ExamSession $session, \App\Services\Proctoring\TrainingSandbox $sandbox): RedirectResponse
    {
        abort_unless(ProctorAssignment::where('exam_session_id', $session->id)->where('user_id', $request->user()->id)->exists() || $request->user()->can('exams.manage'), 403);
        $sandbox->close($session);

        return redirect()->route('proctor.shifts')->with('success', 'Training sandbox removed.');
    }

    public const LAYOUT = ['columns' => 4, 'cameras' => true, 'sort' => 'risk', 'only_flagged' => false, 'compact' => false];

    /** Camera layout chosen by each proctor (Target 109): grid size, cameras on/off, order, flagged only. */
    public function saveLayout(Request $request): JsonResponse
    {
        $data = $request->validate(['columns' => ['required', 'integer', 'in:2,3,4,6,8'], 'cameras' => ['required', 'boolean'], 'sort' => ['required', 'in:risk,seat,name,progress'],
            'only_flagged' => ['required', 'boolean'], 'compact' => ['required', 'boolean']]);
        $user = $request->user();
        $user->forceFill(['preferences' => array_replace((array) $user->preferences, ['proctor_layout' => $data])])->save();

        return response()->json(['ok' => true]);
    }

    /** Live state: every candidate the proctor watches, ordered by risk (Target 30-32, 151). */
    public function live(Request $request, ExamSession $session): JsonResponse
    {
        $assignment = $this->authorizeSession($request, $session);
        $assignment?->forceFill(['last_seen_at' => now()])->saveQuietly();

        $mine = $assignment && in_array($assignment->role, ['proctor', 'invigilator'], true) && ! $request->boolean('all')
            ? DB::table('proctor_candidate')->where('proctor_assignment_id', $assignment->id)->pluck('exam_candidate_id') : null;

        $candidates = ExamCandidate::query()->where('exam_session_id', $session->id)
            ->when($mine, fn ($q) => $q->whereIn('id', $mine))
            ->with(['user:id,name,identifier', 'seat:id,label'])->get(['id', 'user_id', 'seat_id', 'attendance', 'checked_in_at', 'eligibility', 'extra_time_minutes']);

        $attempts = Attempt::where('exam_session_id', $session->id)->whereIn('exam_candidate_id', $candidates->pluck('id'))
            ->orderBy('attempt_no')->get()->keyBy('exam_candidate_id');
        $attemptIds = $attempts->pluck('id');

        $snapshots = EvidenceFile::whereIn('attempt_id', $attemptIds)->where('kind', 'snapshot')
            ->selectRaw('attempt_id, MAX(id) id')->groupBy('attempt_id')->pluck('id', 'attempt_id');
        $openIncidents = Incident::whereIn('attempt_id', $attemptIds)->whereIn('status', ['open', 'investigating', 'escalated'])
            ->selectRaw('attempt_id, COUNT(*) n')->groupBy('attempt_id')->pluck('n', 'attempt_id');
        $help = CandidateMessage::whereIn('attempt_id', $attemptIds)->where('direction', 'to_proctor')->whereNull('acknowledged_at')
            ->get(['id', 'attempt_id', 'body', 'created_at'])->groupBy('attempt_id');
        $total = DB::table('attempt_questions')->whereIn('attempt_id', $attemptIds)->selectRaw('attempt_id, COUNT(*) n')->groupBy('attempt_id')->pluck('n', 'attempt_id');

        $now = now();
        $rows = $candidates->map(function ($c) use ($attempts, $snapshots, $openIncidents, $help, $total, $now) {
            $a = $attempts[$c->id] ?? null;
            $offline = $a && $a->isOpen() && (! $a->last_seen_at || $a->last_seen_at->diffInSeconds($now) > 45);

            return [
                'candidate_id' => $c->id,
                'name' => $c->user?->name, 'identifier' => $c->user?->identifier, 'seat' => $c->seat?->label,
                'attendance' => $c->attendance, 'checked_in' => (bool) $c->checked_in_at, 'eligibility' => $c->eligibility,
                'attempt' => $a ? [
                    'id' => $a->id, 'status' => $a->status, 'channel' => $a->channel,
                    'client_status' => $offline ? 'offline' : ($a->client_status ?? 'active'),
                    'remaining' => $a->remainingSeconds($now), 'answered' => $a->answered_count, 'total' => (int) ($total[$a->id] ?? 0),
                    'violations' => $a->violation_count, 'risk' => $a->risk_score,
                    'last_seen' => $a->last_seen_at?->diffForHumans($now, true),
                    'snapshot_id' => $snapshots[$a->id] ?? null,
                    'incidents' => (int) ($openIncidents[$a->id] ?? 0),
                    'help' => ($help[$a->id] ?? collect())->map(fn ($m) => ['id' => $m->id, 'body' => $m->body])->values(),
                ] : null,
            ];
        })->sortByDesc(fn ($r) => [($r['attempt']['help'] ?? collect())->count() > 0, $r['attempt']['risk'] ?? -1])->values();

        $proctorsOffline = ProctorAssignment::where('exam_session_id', $session->id)
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $now->copy()->subMinutes(2)))
            ->with('user:id,name')->get()->map(fn ($p) => $p->user?->name)->filter()->values();

        return response()->json([
            'server_time' => $now->toIso8601String(),
            'session' => ['paused' => (bool) $session->fresh()->paused_at, 'status' => $session->status],
            'summary' => [
                'expected' => $rows->count(),
                'present' => $rows->where('attendance', 'present')->count(),
                'writing' => $rows->filter(fn ($r) => in_array($r['attempt']['status'] ?? null, ['in_progress'], true))->count(),
                'submitted' => $rows->filter(fn ($r) => in_array($r['attempt']['status'] ?? null, Attempt::CLOSED, true))->count(),
                'offline' => $rows->filter(fn ($r) => ($r['attempt']['client_status'] ?? null) === 'offline')->count(),
                'alerts' => $rows->sum(fn ($r) => $r['attempt']['incidents'] ?? 0),
            ],
            'proctors_offline' => $proctorsOffline,
            'candidates' => $rows,
        ]);
    }

    /** Timestamped timeline of one attempt (Target 37, 110). */
    public function timeline(Request $request, Attempt $attempt): JsonResponse
    {
        $this->authorizeSession($request, $attempt->session);

        return response()->json([
            'events' => ProctoringEvent::where('attempt_id', $attempt->id)->orderByDesc('id')->limit(300)->get(['id', 'type', 'severity', 'data', 'client_time', 'created_at']),
            'messages' => CandidateMessage::where('attempt_id', $attempt->id)->orderByDesc('id')->limit(100)->get(),
            'evidence' => EvidenceFile::where('attempt_id', $attempt->id)->latest('id')->limit(100)->get(['id', 'kind', 'mime', 'size', 'captured_at', 'sha256']),
            'incidents' => Incident::where('attempt_id', $attempt->id)->latest('id')->get(['id', 'type', 'severity', 'status', 'title', 'needs_human_review']),
            'recordings' => \App\Models\MediaRecording::where('attempt_id', $attempt->id)->latest('id')->get(['id', 'kind', 'status', 'duration_seconds', 'started_at']),
            'identity' => [
                'checks' => \App\Models\IdentityCheck::where('attempt_id', $attempt->id)->latest('id')->limit(20)->get(['id', 'kind', 'result', 'evidence_file_id', 'created_at']),
                'reference' => ($p = \App\Models\FaceProfile::where('user_id', $attempt->user_id)->first())
                    ? ['status' => $p->status, 'enrolment' => (bool) $p->photo_path, 'id' => (bool) $p->id_photo_path] : null,
            ],
        ]);
    }

    /** Play a recording made by the media server, when its folder is shared with this server (audited). */
    public function recording(Request $request, \App\Models\MediaRecording $recording)
    {
        $attempt = Attempt::findOrFail($recording->attempt_id);
        $this->authorizeSession($request, $attempt->session);
        $root = rtrim((string) config('platform.livekit.recordings_local'), '/');
        $inner = rtrim((string) config('platform.livekit.recordings_path'), '/');
        abort_unless($root && $recording->status === 'complete' && $recording->location && str_starts_with($recording->location, $inner.'/'), 404);
        $path = realpath($root.substr($recording->location, strlen($inner)));
        abort_unless($path && str_starts_with($path, realpath($root).DIRECTORY_SEPARATOR) && is_file($path), 404); // never outside the recordings folder
        $this->audit->record('evidence.recording_viewed', $attempt, ['recording' => $recording->id]);

        return response()->file($path, ['Content-Type' => 'video/mp4', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** The candidate's reference photo, for a proctor comparing faces during this attempt (audited). */
    public function referencePhoto(Request $request, Attempt $attempt, string $which, \App\Services\Identity\FaceIdentity $faces)
    {
        $this->authorizeSession($request, $attempt->session);
        abort_unless(in_array($which, ['enrolment', 'id'], true) && $attempt->isOpen() || $request->user()->can('exams.manage'), 403);
        $p = \App\Models\FaceProfile::where('user_id', $attempt->user_id)->firstOrFail();
        $bytes = $faces->photo($which === 'id' ? $p->id_photo_path : $p->photo_path);
        abort_unless($bytes, 404);
        $this->audit->record('identity.photo_viewed', $attempt, ['which' => $which]);

        return response($bytes, 200, ['Content-Type' => (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'image/jpeg', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Actions on one candidate (Target 6, 42-43, 58, 112, 152-155). */
    public function act(Request $request, Attempt $attempt): RedirectResponse|JsonResponse
    {
        $this->authorizeSession($request, $attempt->session);
        $data = $request->validate([
            'action' => ['required', 'in:warn,message,pause,resume,lock,unlock,terminate,add_time,submit,ack_help'],
            'body' => ['nullable', 'string', 'max:2000'],
            'minutes' => ['nullable', 'integer', 'min:1', 'max:240'],
            'message_id' => ['nullable', 'integer'],
        ]);
        $by = $request->user()->id;
        $manage = $request->user()->can('exams.manage');

        match ($data['action']) {
            'warn' => $this->attempts->message($attempt, $by, $data['body'] ?? 'Please keep your eyes on your own screen.', 'warning'),
            'message' => $this->attempts->message($attempt, $by, $data['body'] ?? '', 'message'),
            'pause' => $this->attempts->pause($attempt, $by),
            'resume', 'unlock' => $this->attempts->resume($attempt, $by),
            'lock' => $this->attempts->lock($attempt, $by, $data['body'] ?? 'Locked by the invigilator.'),
            'terminate' => $manage ? $this->attempts->terminate($attempt, $by, $data['body'] ?? 'Terminated by the exams office.') : abort(403, 'Only the exams office can terminate an attempt.'),
            'add_time' => $manage ? $this->attempts->addTime($attempt, (int) ($data['minutes'] ?? 5), $by, $data['body'] ?? 'Disruption') : abort(403),
            'submit' => $manage ? $this->attempts->submit($attempt, 'proctor') : abort(403),
            'ack_help' => CandidateMessage::where('attempt_id', $attempt->id)->whereKey($data['message_id'])->update(['acknowledged_at' => now()]),
        };
        if (in_array($data['action'], ['warn', 'message'], true)) {
            $this->audit->record('attempt.'.$data['action'], $attempt, ['body' => $data['body'] ?? null], $by);
        }
        if ($attempt->session->is_practice) {
            $task = ['ack_help' => 'ack_help', 'warn' => 'warn', 'unlock' => 'unlock', 'resume' => 'unlock'][$data['action']] ?? null;
            $task && app(\App\Services\Proctoring\TrainingSandbox::class)->record($attempt->session, $by, $task);
        }

        return $request->wantsJson() ? response()->json(['ok' => true]) : back();
    }

    /** Emergency pause / resume / close of a whole sitting (Target 42, 95, 109). */
    public function sessionAction(Request $request, ExamSession $session): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()->can('exams.manage') || $this->isChief($request, $session), 403);
        $data = $request->validate(['action' => ['required', 'in:pause,resume,open,close,cover'], 'reason' => ['nullable', 'string', 'max:500']]);
        $by = $request->user()->id;

        DB::transaction(function () use ($session, $data, $by) {
            if ($data['action'] === 'pause' && ! $session->paused_at) {
                $session->update(['paused_at' => now(), 'status' => 'paused']);
                Attempt::where('exam_session_id', $session->id)->where('status', 'in_progress')->get()->each(fn ($a) => $this->attempts->pause($a, $by));
                Incident::create(['exam_session_id' => $session->id, 'type' => 'disruption', 'severity' => 'high', 'status' => 'open', 'source' => 'proctor',
                    'title' => 'Sitting paused', 'description' => $data['reason'] ?? 'Emergency pause', 'opened_by' => $by]);
            } elseif ($data['action'] === 'resume' && $session->paused_at) {
                $session->update(['paused_at' => null, 'status' => 'open']);
                Attempt::where('exam_session_id', $session->id)->where('status', 'paused')->get()->each(fn ($a) => $this->attempts->resume($a, $by));
            } elseif ($data['action'] === 'open') {
                $session->update(['status' => 'open']);
            } elseif ($data['action'] === 'cover') {
                // move candidates of proctors whose console is offline to proctors who are online
                $all = ProctorAssignment::where('exam_session_id', $session->id)->whereIn('role', ['proctor', 'invigilator'])->get();
                $cut = now()->subSeconds(\App\Services\Proctoring\ProctorWatch::OFFLINE_SECONDS);
                [$online, $offline] = $all->partition(fn ($a) => $a->last_seen_at && $a->last_seen_at->gt($cut));
                abort_if($online->isEmpty(), 422, 'No proctor is online to take the candidates. Assign another proctor first.');
                $moving = DB::table('proctor_candidate')->whereIn('proctor_assignment_id', $offline->pluck('id'))->pluck('exam_candidate_id');
                DB::table('proctor_candidate')->whereIn('proctor_assignment_id', $offline->pluck('id'))->delete();
                $online = $online->values();
                foreach ($moving->values() as $i => $cid) {
                    DB::table('proctor_candidate')->insert(['proctor_assignment_id' => $online[$i % $online->count()]->id, 'exam_candidate_id' => $cid]);
                }
            } elseif ($data['action'] === 'close') {
                $session->update(['status' => 'closed', 'paused_at' => null]);
                Attempt::where('exam_session_id', $session->id)->whereIn('status', Attempt::OPEN)->get()->each(fn ($a) => $this->attempts->submit($a, 'session_closed'));
            }
        });
        $this->audit->record('session.'.$data['action'], $session, ['reason' => $data['reason'] ?? null], $by);

        return $request->wantsJson() ? response()->json(['ok' => true]) : back();
    }

    public function snapshot(Request $request, EvidenceFile $evidence, EvidenceStore $store)
    {
        abort_unless($evidence->attempt, 404);
        $this->authorizeSession($request, $evidence->attempt->session);

        return $store->response($evidence, $request->user(), $request->boolean('download'));
    }

    private function authorizeSession(Request $request, ExamSession $session): ?ProctorAssignment
    {
        $assignment = ProctorAssignment::where('exam_session_id', $session->id)->where('user_id', $request->user()->id)->first();
        abort_unless($assignment || $request->user()->can('exams.manage') || \App\Support\Exams\ExamAccess::can($request->user(), $session->exam), 403);

        return $assignment;
    }

    private function isChief(Request $request, ExamSession $session): bool
    {
        return ProctorAssignment::where('exam_session_id', $session->id)->where('user_id', $request->user()->id)->whereIn('role', ['chief', 'supervisor'])->exists();
    }
}
