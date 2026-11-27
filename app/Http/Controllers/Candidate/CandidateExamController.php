<?php

namespace App\Http\Controllers\Candidate;

use App\Http\Controllers\Controller;
use App\Models\Appeal;
use App\Models\Attempt;
use App\Models\ExamCandidate;
use App\Models\Result;
use App\Services\Exams\AttemptService;
use App\Services\Exams\ExamException;
use App\Services\Exams\SebConfig;
use App\Support\Exams\ExamSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class CandidateExamController extends Controller
{
    public function index(Request $request): Response|\Illuminate\Http\RedirectResponse
    {
        // while an emergency exam is open, it is the only thing the student sees
        if (\App\Support\Exams\OpenExams::emergency($request->user())->isNotEmpty()) {
            return redirect('/my/exams/now');
        }

        $rows = ExamCandidate::with(['session.exam:id,title,type,duration_minutes,category,channels,max_attempts', 'session.venue:id,name'])
            ->where('user_id', $request->user()->id)
            ->latest('id')->limit(100)->get()
            ->filter(fn ($c) => $c->session && $c->session->exam && $c->session->exam->isOnScreen())
            ->map(fn (ExamCandidate $c) => [
                'candidate_id' => $c->id,
                'exam' => $c->session->exam->title,
                'type' => $c->session->exam->type,
                'category' => $c->session->exam->category,
                'starts_at' => $c->session->starts_at->toIso8601String(),
                'ends_at' => $c->session->ends_at->toIso8601String(),
                'duration' => $c->session->exam->duration_minutes + $c->extra_time_minutes,
                'venue' => $c->session->venue?->name,
                'mode' => $c->session->mode,
                'channels' => $c->session->exam->channels ?: ['web', 'desktop', 'seb'],
                'eligible' => in_array($c->eligibility, ['eligible', 'override'], true),
                'eligibility_reason' => $c->eligibility_reason,
                'practice' => $c->session->is_practice,
                'status' => Attempt::where('exam_candidate_id', $c->id)->latest('id')->value('status'),
                ...self::attemptsSummary($c),
            ])->values();

        return Inertia::render('candidate/exams', ['exams' => $rows]);
    }

    /** Emergency exams open now: shown on their own, each one click from the first question. */
    public function now(Request $request): Response|\Illuminate\Http\RedirectResponse
    {
        $open = \App\Support\Exams\OpenExams::emergency($request->user());

        if ($open->isEmpty()) {
            return redirect('/my/exams');
        }

        return Inertia::render('candidate/exam-now', ['exams' => $open]);
    }

    public function room(Request $request, ExamCandidate $candidate): Response
    {
        abort_unless($candidate->user_id === $request->user()->id, 403);
        $candidate->load('session.exam', 'session.venue', 'seat');
        $exam = $candidate->session->exam;

        return Inertia::render('candidate/exam-room', [
            'candidate' => [
                'id' => $candidate->id,
                'seat' => $candidate->seat?->label,
                'extra_time' => $candidate->extra_time_minutes,
                'accommodations' => $candidate->accommodations ?? [],
                'consent_given' => $candidate->consent_given,
                ...self::attemptsSummary($candidate),
            ],
            'exam' => [
                'title' => $exam->title, 'type' => $exam->type, 'instructions' => $exam->instructions,
                'duration' => $exam->duration_minutes, 'channels' => $exam->channels ?: ['web', 'desktop', 'seb'],
                'needs_pin' => (bool) ($candidate->session->access_pin_hash || $candidate->pin_hash),
                'proctoring' => ExamSettings::forCandidate($exam->proctoring, $candidate->user_id, $exam->id),
                'starts_at' => $candidate->session->starts_at->toIso8601String(),
                'venue' => $candidate->session->venue?->name,
                'notice' => \App\Support\Policy::forCampus($candidate->session->venue?->campus)['exam_day_notice'] ?? '',
                // most specific first, so "Edg" and "Android" are recognised before "Chrome"
                'compat' => collect(config('compatibility.browsers'))->sortBy(fn ($b) => array_search($b['match'], ['MSIE|Trident|Opera Mini|UCBrowser', 'iPhone|iPad', 'Android', 'Edg', 'Firefox', 'Chrome', 'Version/'], true))->values()->all(),
                'practice' => $candidate->session->is_practice,
                'emergency' => (bool) data_get($exam->settings, 'emergency', false),
            ],
        ]);
    }

    /** How many attempts the candidate has used, and whether the next start is a re-attempt. */
    public static function attemptsSummary(ExamCandidate $candidate): array
    {
        $statuses = Attempt::where('exam_candidate_id', $candidate->id)->pluck('status');
        $max = max(1, (int) $candidate->session->exam->max_attempts);
        $open = $statuses->contains(fn ($s) => in_array($s, Attempt::OPEN, true));

        return [
            'attempts_used' => $statuses->count(),
            'max_attempts' => $max,
            'has_open_attempt' => $open,
            'can_reattempt' => ! $open && $statuses->isNotEmpty() && $statuses->count() < $max,
        ];
    }

    public function start(Request $request, ExamCandidate $candidate, AttemptService $attempts): JsonResponse
    {
        abort_unless($candidate->user_id === $request->user()->id, 403);
        $data = $request->validate([
            'pin' => ['nullable', 'string', 'max:20'], 'consent' => ['boolean'],
            'checks' => ['sometimes', 'array'], 'checks.*' => ['boolean'],
            'check_details' => ['sometimes', 'array', 'max:20'], 'check_details.*' => ['nullable', 'string', 'max:200'],
            'off' => ['sometimes', 'nullable', 'array'], 'off.from' => ['required_with:off', 'integer'], 'off.to' => ['required_with:off', 'integer'], 'off.kind' => ['nullable', 'in:off,asleep'],
        ]);

        // Pre-exam checks chosen for this exam must all have passed on the candidate's screen before a new
        // attempt starts (a reload of an attempt already under way does not repeat them). The room scan is
        // done just after starting, before the paper is shown.
        $exam = $candidate->session->exam;
        $required = array_values(array_diff(ExamSettings::forCandidate($exam->proctoring, $candidate->user_id, $exam->id)['pre_checks'], ['room_scan']));
        $resuming = Attempt::where('exam_candidate_id', $candidate->id)->whereIn('status', Attempt::OPEN)->exists();
        $passed = array_keys(array_filter((array) ($data['checks'] ?? [])));
        $missing = $resuming ? [] : array_values(array_diff($required, $passed));
        if ($missing) {
            return response()->json([
                'message' => 'Finish these checks before starting: '.implode(', ', array_map(fn ($c) => ExamSettings::PRE_CHECKS[$c][0], $missing)).'.',
                'reason' => 'pre_checks', 'missing' => $missing,
            ], 422);
        }

        // coming back after the computer went off: give the time back before the attempt is touched
        if ($resuming && ! empty($data['off']) && ($open = Attempt::where('exam_candidate_id', $candidate->id)->where('status', 'in_progress')->latest('id')->first())) {
            $attempts->creditDeviceOff($open, $data['off']);
        }

        try {
            [$attempt, $token, $resumed] = $attempts->start($candidate, $request, $data['pin'] ?? null, (bool) ($data['consent'] ?? false));
        } catch (ExamException $e) {
            return $e->render();
        }
        if (! $resumed && $required) {
            \App\Models\ProctoringEvent::insert([
                'tenant_id' => $attempt->tenant_id, 'attempt_id' => $attempt->id, 'type' => 'pre_checks_passed', 'severity' => 0,
                'data' => json_encode(['checks' => $required, 'details' => array_slice((array) ($data['check_details'] ?? []), 0, 20)]),
                'client_time' => null, 'created_at' => now()->format('Y-m-d H:i:s.v'),
            ]);
        }

        return response()->json(['token' => $token, 'resumed' => $resumed, 'attempt' => $attempts->state($attempt)]);
    }

    public function sebConfig(Request $request, ExamCandidate $candidate, SebConfig $seb): HttpResponse
    {
        abort_unless($candidate->user_id === $request->user()->id, 403);

        return $seb->download($candidate->session->exam, route('candidate.room', $candidate));
    }

    public function results(Request $request): Response
    {
        $results = Result::with('exam:id,title,type,total_mark')->where('user_id', $request->user()->id)
            ->where('status', 'published')->latest('published_at')->get()
            ->map(fn (Result $r) => [
                'id' => $r->id, 'exam' => $r->exam->title, 'type' => $r->exam->type, 'score' => $r->final_score,
                'out_of' => (float) ($r->exam->total_mark ?: $r->max_score), 'grade' => $r->grade, 'published_at' => $r->published_at?->toDateString(),
                'appeal' => Appeal::where('result_id', $r->id)->latest('id')->value('status'),
            ]);

        return Inertia::render('candidate/results', ['results' => $results]);
    }

    public function appeal(Request $request, Result $result): RedirectResponse
    {
        abort_unless($result->user_id === $request->user()->id && $result->status === 'published', 403);
        $data = $request->validate(['statement' => ['required', 'string', 'min:20', 'max:5000']]);
        abort_if(Appeal::where('result_id', $result->id)->whereIn('status', ['submitted', 'reviewing'])->exists(), 422, 'An appeal is already in progress.');

        Appeal::create(['user_id' => $request->user()->id, 'result_id' => $result->id, 'kind' => 'recheck', 'statement' => $data['statement']]);

        return back()->with('success', 'Your recheck request has been sent to the exams office.');
    }
}
