<?php

namespace App\Http\Controllers\Exam;

use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Models\CandidateMessage;
use App\Services\Evidence\EvidenceStore;
use App\Services\Exams\AttemptService;
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
        return response()->json($this->attempts->paper($this->attempt($request)))->header('Cache-Control', 'no-store');
    }

    public function answers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'answers' => ['required', 'array', 'max:200'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.seq' => ['required', 'integer', 'min:0'],
            'answers.*.flagged' => ['sometimes', 'boolean'],
            'answers.*.response' => ['nullable', 'array'],
        ]);

        return response()->json($this->attempts->save($this->attempt($request), $data['answers']));
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:16'],
            'events' => ['sometimes', 'array', 'max:50'],
            'events.*.type' => ['required', 'string', 'max:40'],
            'events.*.at' => ['nullable', 'date'],
            'events.*.data' => ['nullable', 'array'],
        ]);

        return response()->json($this->attempts->heartbeat($this->attempt($request), $data['status'] ?? null, $data['events'] ?? []));
    }

    public function submit(Request $request): JsonResponse
    {
        $attempt = $this->attempt($request);
        // Final save of anything still queued on the client, then submit, in one call
        if ($request->filled('answers') && $attempt->isOpen() && ! in_array($attempt->status, ['locked', 'paused'], true)) {
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
