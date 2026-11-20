<?php

namespace App\Http\Controllers\Exam;

use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Models\CandidateMessage;
use App\Services\Evidence\EvidenceStore;
use App\Services\Exams\AttemptService;
use App\Support\Exams\ExamSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The candidate's exam API, used by the web exam room, the desktop app and SEB alike. */
class ExamApiController extends Controller
{
    public function __construct(private AttemptService $attempts) {}

    private function attempt(Request $request): Attempt
    {
        return $request->attributes->get('attempt');
    }

    public function paper(Request $request): JsonResponse
    {
        $attempt = $this->attempt($request);
        // a room scan chosen as a pre-exam check comes first: no paper until the video has arrived
        $p = ExamSettings::forCandidate($attempt->exam->proctoring, $attempt->user_id, $attempt->exam_id);
        if ($attempt->isOpen() && in_array('room_scan', $p['pre_checks'], true)
            && ! \App\Models\EvidenceFile::where('attempt_id', $attempt->id)->where('kind', 'room_scan')->exists()) {
            return response()->json(['message' => 'Show your room and desk to the camera before the paper opens.', 'reason' => 'room_scan'], 428);
        }

        return response()->json($this->attempts->paper($attempt))->header('Cache-Control', 'no-store');
    }

    public function answers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'answers' => ['required', 'array', 'max:200'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.seq' => ['required', 'integer', 'min:0'],
            'answers.*.flagged' => ['sometimes', 'boolean'],
            'answers.*.response' => ['nullable', 'array'],
            'answers.*.at' => ['nullable', 'integer'],
            // the computer was off (power cut, crash) between these two moments: time is given back
            'off' => ['sometimes', 'nullable', 'array'],
            'off.from' => ['required_with:off', 'integer'],
            'off.to' => ['required_with:off', 'integer'],
            'off.kind' => ['nullable', 'in:off,asleep'],
            // a check-in can ride along with a save, so a busy exam room sends half the requests
            'status' => ['nullable', 'string', 'max:16'],
            'events' => ['sometimes', 'array', 'max:50'],
            'events.*.type' => ['required', 'string', 'max:40'],
            'events.*.at' => ['nullable', 'date'],
            'events.*.data' => ['nullable', 'array'],
        ]);
        $attempt = $this->attempt($request);
        if ($request->attributes->get('superseded')) {
            $saved = $this->attempts->saveMissing($attempt, $data['answers']);

            return response()->json(['message' => 'This exam was opened in another window or device. Only the newest one can continue.', 'reason' => 'session_moved'] + $saved, 409);
        }
        $credited = ! empty($data['off']) ? $this->attempts->creditDeviceOff($attempt, $data['off'], $data['answers']) : 0;
        $saved = $this->attempts->save($attempt, $data['answers']);
        if ($request->has('status') && $attempt->isOpen()) {
            $saved += $this->attempts->heartbeat($attempt, $data['status'] ?? null, $data['events'] ?? []);
        }
        $saved['credited'] = $credited;
        $saved['attempt'] ??= $this->attempts->state($attempt->fresh());

        return response()->json($saved);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:16'],
            'events' => ['sometimes', 'array', 'max:50'],
            'events.*.type' => ['required', 'string', 'max:40'],
            'events.*.at' => ['nullable', 'date'],
            'events.*.data' => ['nullable', 'array'],
            'off' => ['sometimes', 'nullable', 'array'],
            'off.from' => ['required_with:off', 'integer'],
            'off.to' => ['required_with:off', 'integer'],
            'off.kind' => ['nullable', 'in:off,asleep'],
        ]);
        $attempt = $this->attempt($request);
        if ($request->attributes->get('superseded')) {
            $saved = $this->attempts->saveMissing($attempt, $data['answers']);

            return response()->json(['message' => 'This exam was opened in another window or device. Only the newest one can continue.', 'reason' => 'session_moved'] + $saved, 409);
        }
        $credited = ! empty($data['off']) ? $this->attempts->creditDeviceOff($attempt, $data['off']) : 0;

        return response()->json($this->attempts->heartbeat($attempt, $data['status'] ?? null, $data['events'] ?? []) + ['credited' => $credited]);
    }

    public function submit(Request $request): JsonResponse
    {
        $attempt = $this->attempt($request);
        // Final save of anything still queued on the client, then submit, in one call
        if ($request->filled('off')) {
            $this->attempts->creditDeviceOff($attempt, (array) $request->input('off'), (array) $request->input('answers', []));
        }
        $writable = $attempt->isOpen() && ! in_array($attempt->status, ['locked', 'paused'], true);
        if ($request->filled('answers') && ($writable || $this->attempts->acceptsLateSync($attempt))) {
            $this->attempts->save($attempt, (array) $request->input('answers'));
        }
        $attempt = $this->attempts->submit($attempt->fresh(), 'candidate');
        $exam = $attempt->exam;
        $showScore = (bool) data_get($exam->settings, 'show_score', false) && ! $attempt->needs_marking;

        return response()->json([
            'attempt' => $this->attempts->state($attempt),
            'receipt' => ['submitted_at' => $attempt->submitted_at?->toIso8601String(), 'hash' => $attempt->submission_hash, 'answered' => $attempt->answered_count],
            'score' => $showScore ? ['raw' => $attempt->score_raw, 'max' => $attempt->score_max] : null,
        ]);
    }

    /**
     * A file handed in as the answer to a file-upload question (a drawing, a calculation sheet, a
     * report). Only the kinds and size the question allows. The answer itself is then saved as usual.
     */
    public function file(Request $request): JsonResponse
    {
        $attempt = $this->attempt($request);
        $data = $request->validate(['question_id' => ['required', 'integer'], 'file' => ['required', 'file', 'max:102400']]);
        $q = \Illuminate\Support\Facades\DB::table('attempt_questions as aq')->join('questions as q', 'q.id', '=', 'aq.question_id')
            ->where('aq.attempt_id', $attempt->id)->where('aq.question_id', $data['question_id'])->first(['q.type', 'q.accepted_answers']);
        abort_unless($q && $q->type === 'file_upload', 422, 'This question does not take a file.');
        abort_unless($attempt->isOpen() && in_array($attempt->status, ['in_progress'], true) && $attempt->deadline_at->copy()->addSeconds((int) ($attempt->session?->grace_seconds ?? 30))->isFuture(), 409, 'Time is up.');
        $rules = array_replace(['kinds' => ['image', 'pdf', 'document'], 'max_mb' => 10], (array) (json_decode((string) $q->accepted_answers, true)['file'] ?? []));
        $ext = strtolower($data['file']->getClientOriginalExtension());
        $allowed = collect($rules['kinds'])->flatMap(fn ($k) => \App\Models\Question::FILE_KINDS[$k] ?? [])->all();
        if (! in_array($ext, $allowed, true)) {
            return response()->json(['message' => 'This question takes '.implode(', ', array_map(fn ($e) => '.'.$e, $allowed)).' files.'], 422);
        }
        if ($data['file']->getSize() > $rules['max_mb'] * 1048576) {
            return response()->json(['message' => 'The file is larger than '.$rules['max_mb'].' MB.'], 422);
        }
        $path = $data['file']->storeAs('attempt-files/'.$attempt->id, \Illuminate\Support\Str::uuid().'.'.$ext, 'local');
        $id = \Illuminate\Support\Facades\DB::table('attempt_files')->insertGetId([
            'tenant_id' => $attempt->tenant_id, 'attempt_id' => $attempt->id, 'question_id' => (int) $data['question_id'],
            'name' => mb_substr($data['file']->getClientOriginalName(), 0, 200), 'mime' => mb_substr((string) $data['file']->getMimeType(), 0, 120),
            'size' => $data['file']->getSize(), 'path' => $path, 'sha256' => hash_file('sha256', $data['file']->getRealPath()), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['id' => $id, 'name' => mb_substr($data['file']->getClientOriginalName(), 0, 200), 'size' => $data['file']->getSize()]);
    }

    public function evidence(Request $request, EvidenceStore $store): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:'.implode(',', EvidenceStore::KINDS)],
            'file' => ['required', 'file', 'max:25600'],
            'captured_at' => ['nullable', 'date'],
        ]);
        $attempt = $this->attempt($request);
        abort_unless($attempt->isOpen(), 409);
        $file = $request->file('file');
        $evidence = $store->store($attempt, $data['kind'], $file->get(), $file->getMimeType() ?: 'application/octet-stream');

        return response()->json(['id' => $evidence->id, 'sha256' => $evidence->sha256], 201);
    }

    /**
     * One piece of a camera or screen recording made in the browser (no media server). Sending the
     * same piece again is harmless, and pieces may still arrive for a while after submitting.
     */
    public function recording(Request $request, \App\Services\Media\BrowserRecordings $recordings): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,64}$/'],
            'kind' => ['required', 'in:'.implode(',', \App\Services\Media\BrowserRecordings::KINDS)],
            'seq' => ['required', 'integer', 'min:0', 'max:1000000'],
            'offset_ms' => ['nullable', 'integer', 'min:0', 'max:86400000'],
            'final' => ['nullable', 'boolean'],
            'file' => ['required', 'file', 'max:8192'],
        ]);
        $attempt = $this->attempt($request);
        abort_unless($recordings->accepts($attempt), 409, 'This exam is closed.');
        abort_unless($recordings->allowedKind($attempt, $data['kind']), 403, 'This exam does not record that.');
        abort_unless(app(\App\Services\Modules\ModuleRegistry::class)->isEnabled(\App\Models\Tenant::find($attempt->tenant_id), 'live_proctoring'), 403, 'Recording is not part of this subscription.');
        $file = $request->file('file');
        $mime = str_starts_with((string) $file->getMimeType(), 'video/mp4') || str_contains((string) $file->getClientOriginalName(), '.mp4') ? 'video/mp4' : 'video/webm';
        $rec = $recordings->store($attempt, $data['key'], $data['kind'], $mime, (int) $data['seq'], (int) ($data['offset_ms'] ?? 0), $file->get(), (bool) ($data['final'] ?? false));

        return response()->json(['recording' => $rec->id, 'seq' => (int) $data['seq'], 'status' => $rec->status]);
    }

    /** Identity check at entry or during the exam; the decision is made on the server. */
    public function identity(Request $request, \App\Services\Identity\FaceIdentity $faces): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:entry,periodic'], 'faces' => ['required', 'integer', 'min:0', 'max:20'],
            'descriptor' => ['nullable', 'string', 'max:5000'], 'photo' => ['nullable', 'file', 'image', 'max:4096'],
        ]);
        $attempt = $this->attempt($request);
        abort_unless($attempt->isOpen(), 409);
        $descriptor = isset($data['descriptor']) ? json_decode($data['descriptor'], true) : null;
        $check = $faces->check($attempt, $data['kind'], is_array($descriptor) ? array_values($descriptor) : null, (int) $data['faces'], $request->file('photo'));

        // the candidate learns the outcome, never the score or the template
        return response()->json(['result' => $check->result, 'message' => match ($check->result) {
            'match' => 'Identity confirmed.',
            'review' => 'Photo taken. A proctor will confirm your identity.',
            'mismatch' => 'We could not confirm your identity. A proctor will check — carry on with your exam.',
            'no_profile' => 'You have no verified face on record. A proctor will check your identity.',
            'multiple_faces' => 'More than one face is in view. Make sure you are alone.',
            default => 'We could not see your face clearly. Face the camera in good light.',
        }]);
    }

    public function acknowledge(Request $request, int $message): JsonResponse
    {
        CandidateMessage::where('attempt_id', $this->attempt($request)->id)->whereKey($message)->whereNull('acknowledged_at')
            ->update(['acknowledged_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /** "I need help" / technical support from inside the exam (Target 108, 234). */
    public function help(Request $request): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:1000']]);
        CandidateMessage::create([
            'attempt_id' => $this->attempt($request)->id, 'direction' => 'to_proctor', 'kind' => 'help',
            'body' => $data['body'], 'created_at' => now(),
        ]);

        return response()->json(['ok' => true], 201);
    }
}
