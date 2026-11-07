<?php

namespace App\Services\Exams;

use App\Models\Attempt;
use App\Models\CandidateMessage;
use App\Models\ExamCandidate;
use App\Models\Incident;
use App\Models\ProctoringEvent;
use App\Services\Audit\AuditLogger;
use App\Support\Exams\ExamSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Everything that changes an attempt. All timing decisions use the server clock and the
 * deadline stored on the attempt (Target 20-22, 24-27, 42-43, 58, 93-95, 130-136, 187-190).
 */
class AttemptService
{
    public function __construct(
        private PaperBuilder $papers,
        private Marker $marker,
        private ChannelVerifier $channels,
        private AuditLogger $audit,
    ) {}

    /**
     * Start a new attempt or resume the open one. Returns the attempt and a fresh token;
     * any token issued before stops working (one active window per candidate).
     *
     * @return array{0: Attempt, 1: string, 2: bool}  attempt, token, resumed
     */
    public function start(ExamCandidate $candidate, Request $request, ?string $pin = null, bool $consent = false): array
    {
        $candidate->loadMissing('session.exam', 'seat');
        $session = $candidate->session;
        $exam = $session->exam;
        $policy = ExamSettings::forCandidate($exam->proctoring, $candidate->user_id, $exam->id);
        $now = now();

        if (! $exam->isOnScreen()) {
            throw new ExamException('This exam is marked by examiners and is not taken on screen.', 'not_on_screen');
        }
        if (! $session->is_practice && ! in_array($exam->status, ['scheduled', 'live'], true)) {
            throw new ExamException('This exam has not been approved and scheduled yet.', 'not_scheduled', 403);
        }
        if (! in_array($candidate->eligibility, ['eligible', 'override'], true)) {
            throw new ExamException('You are not eligible for this exam: '.($candidate->eligibility_reason ?: 'contact the exams office').'.', 'ineligible', 403);
        }
        if ($session->status === 'closed') {
            throw new ExamException('This exam session has closed.', 'closed', 403);
        }
        if ($session->paused_at) {
            throw new ExamException('The exam is paused by the exams office. Please wait.', 'paused', 423);
        }

        $open = Attempt::where('exam_candidate_id', $candidate->id)->whereIn('status', Attempt::OPEN)->latest('id')->first();

        // no new exams start during planned maintenance; attempts already running carry on
        if (! $open && ($w = \App\Support\Maintenance::blocksExamsAt(now()))) {
            throw new ExamException('The platform is under planned maintenance until '.\Carbon\Carbon::parse($w['ends_at'])->format('H:i').'. Please start your exam after that.', 'maintenance', 503);
        }
        $verified = $this->channels->verify($request, $exam);
        if (! $verified['ok']) {
            throw new ExamException($verified['reason'], 'wrong_channel', 403);
        }
        if ($policy['device_whitelist'] && $session->mode === 'hall' && app(\App\Services\Modules\ModuleRegistry::class)->isEnabled($exam->tenant, 'cbt_halls')) {
            $device = $verified['device'];
            if (! $device) {
                throw new ExamException('This exam must be taken on an approved hall computer.', 'device_not_approved', 403);
            }
            if ($candidate->seat?->device_id && $candidate->seat->device_id !== $device->id) {
                throw new ExamException('Please sit at your assigned seat '.$candidate->seat->label.'.', 'wrong_seat', 403);
            }
        }

        // computers approved by IP: checked for every new start, and when an open exam moves to another computer
        if (! $open || $open->ip !== $request->ip()) {
            app(ExamComputers::class)->gate($request, $exam, $session, $candidate->user ?? \App\Models\User::findOrFail($candidate->user_id));
        }

        if (! $open) {
            if ($now->lt($session->starts_at)) {
                throw new ExamException('The exam starts at '.$session->starts_at->format('H:i').'.', 'not_started', 403);
            }
            if ($now->gt(ExamSettings::lastStart($exam, $session))) {
                $rule = ExamSettings::settings($exam->settings);
                throw new ExamException($rule['late_start'] === 'cutoff'
                    ? 'Starting closed '.$rule['late_start_minutes'].' minutes after the exam began. Speak to the invigilator.'
                    : 'Late entry for this exam has closed. Speak to the invigilator.', 'late', 403);
            }
            if ($session->mode === 'hall' && ! $session->is_practice && ! $candidate->checked_in_at && data_get($exam->settings, 'require_check_in', true)) {
                throw new ExamException('Please check in with the invigilator first.', 'not_checked_in', 403);
            }
            if ($session->access_pin_hash && ! ($pin && Hash::check($pin, $session->access_pin_hash))) {
                throw new ExamException('Enter the exam PIN given in the hall.', 'pin_required', 403);
            }
            if ($candidate->pin_hash && ! ($pin && Hash::check($pin, $candidate->pin_hash))) {
                throw new ExamException('Enter the PIN on your exam slip.', 'pin_required', 403);
            }
            if ($policy['require_consent'] && ! $consent && ! $candidate->consent_given) {
                throw new ExamException('Please read and accept the monitoring notice.', 'consent_required', 403);
            }
            $used = Attempt::where('exam_candidate_id', $candidate->id)->count();
            if ($used >= max(1, $exam->max_attempts)) {
                throw new ExamException('You have used all your attempts for this exam.', 'no_attempts_left', 403);
            }
        }

        if ($consent && ! $candidate->consent_given) {
            $candidate->forceFill(['consent_given' => true])->save();
        }

        $resumed = (bool) $open;
        $attempt = $open ?? $this->create($candidate, $verified['channel'], $now);

        if ($resumed) {
            $moved = $attempt->ip !== $request->ip() || $attempt->user_agent !== substr((string) $request->userAgent(), 0, 255);
            $this->event($attempt, $moved ? 'session_moved' : 'session_resumed', ['ip' => $request->ip()], $moved ? 3 : 1);
        }

        $attempt->forceFill([
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'channel' => $verified['channel'],
            'device_hash' => $request->header('X-Platform-Device') ? hash('sha256', (string) $request->header('X-Platform-Device')) : $attempt->device_hash,
            'last_seen_at' => $now,
        ])->save();

        $token = AttemptToken::issue($attempt);

        return [$attempt->fresh(), $token, $resumed];
    }

    private function create(ExamCandidate $candidate, string $channel, \Carbon\CarbonInterface $now): Attempt
    {
        $session = $candidate->session;
        $exam = $session->exam;
        $extra = $candidate->extra_time_minutes * 60;
        $deadline = $now->copy()->addMinutes($exam->duration_minutes)->addSeconds($extra);
        $hardEnd = $session->ends_at->copy()->addSeconds($extra);
        if ($deadline->gt($hardEnd)) {
            $deadline = $hardEnd;
        }
        // the subscription's monthly attempt allowance (resuming an attempt never counts again)
        app(\App\Services\Billing\Entitlements::class)->check('attempts_monthly');

        try {
            return DB::transaction(function () use ($candidate, $session, $exam, $channel, $now, $deadline) {
                $attempt = Attempt::create([
                    'exam_id' => $exam->id,
                    'exam_session_id' => $session->id,
                    'exam_candidate_id' => $candidate->id,
                    'user_id' => $candidate->user_id,
                    'attempt_no' => Attempt::where('exam_candidate_id', $candidate->id)->count() + 1,
                    'status' => 'in_progress',
                    'channel' => $channel,
                    'started_at' => $now,
                    'deadline_at' => $deadline,
                ]);
                $this->papers->build($attempt);
                if (! $candidate->checked_in_at && $session->mode === 'remote') {
                    $candidate->forceFill(['checked_in_at' => $now, 'attendance' => 'present'])->save();
                } elseif ($candidate->attendance === 'expected') {
                    $candidate->forceFill(['attendance' => 'present'])->save();
                }
                $this->event($attempt, 'attempt_started', ['deadline' => $deadline->toIso8601String()], 1);

                return $attempt;
            });
        } catch (UniqueConstraintViolationException) {
            // Two clicks / two tabs at the same instant: the other request created it.
            return Attempt::where('exam_candidate_id', $candidate->id)->whereIn('status', Attempt::OPEN)->latest('id')->firstOrFail();
        }
    }

    public function paper(Attempt $attempt): array
    {
        $this->guardOpenForReading($attempt);
        $paper = json_decode($attempt->paper->payload, true);
        $answers = DB::table('attempt_answers')->where('attempt_id', $attempt->id)->get(['question_id', 'response', 'flagged', 'client_seq'])
            ->mapWithKeys(fn ($a) => [$a->question_id => ['response' => json_decode($a->response ?? 'null', true), 'flagged' => (bool) $a->flagged, 'seq' => (int) $a->client_seq]]);

        return [
            'attempt' => $this->state($attempt),
            'paper' => $paper,
            'answers' => $answers,
            'settings' => ExamSettings::settings($attempt->exam->settings),
            'proctoring' => ExamSettings::forCandidate($attempt->exam->proctoring, $attempt->user_id, $attempt->exam_id),
            'accommodations' => $attempt->candidate->accommodations ?? [],
        ];
    }

    /**
     * Save one or more answers. Each carries the client's increasing sequence number; an
     * older or repeated save never overwrites a newer one, so retries are always safe.
     *
     * @param  list<array{question_id:int, response:mixed, seq:int, flagged?:bool}>  $items
     */
    public function save(Attempt $attempt, array $items): array
    {
        $this->expireIfDue($attempt);
        $this->guardWritable($attempt);

        $meta = $this->questionMeta($attempt);

        $rows = [];
        $rejected = [];
        foreach (array_slice($items, 0, 200) as $item) {
            $qid = (int) ($item['question_id'] ?? 0);
            if (! isset($meta[$qid])) {
                $rejected[] = $qid;

                continue;
            }
            $response = $this->normaliseResponse($meta[$qid][0], $item['response'] ?? null, $meta[$qid][1]);
            if ($response === false) {
                $rejected[] = $qid;

                continue;
            }
            $rows[] = [
                'attempt_id' => $attempt->id,
                'question_id' => $qid,
                'response' => $response === null ? null : json_encode($response),
                'client_seq' => max(0, (int) ($item['seq'] ?? 0)),
                'flagged' => (bool) ($item['flagged'] ?? false),
                'answered_at' => now()->format('Y-m-d H:i:s.v'),
            ];
        }

        if ($rows) {
            $this->upsertAnswers($rows);
            DB::table('attempts')->where('id', $attempt->id)->update([
                'answered_count' => DB::raw('(SELECT COUNT(*) FROM attempt_answers WHERE attempt_answers.attempt_id = '.(int) $attempt->id.' AND attempt_answers.response IS NOT NULL)'),
                'last_seen_at' => now()->format('Y-m-d H:i:s.v'),
            ]);
        }

        return ['saved' => array_column($rows, 'question_id'), 'rejected' => $rejected, 'remaining' => $attempt->remainingSeconds()];
    }

    /** One statement for the whole batch; the newest client_seq wins (MySQL/MariaDB and SQLite). */
    private function upsertAnswers(array $rows): void
    {
        $cols = ['attempt_id', 'question_id', 'response', 'client_seq', 'flagged', 'answered_at'];
        $place = '('.implode(',', array_fill(0, count($cols), '?')).')';
        $values = implode(',', array_fill(0, count($rows), $place));
        $bindings = [];
        foreach ($rows as $r) {
            foreach ($cols as $c) {
                $bindings[] = $r[$c];
            }
        }
        $sql = 'INSERT INTO attempt_answers ('.implode(',', $cols).') VALUES '.$values;

        if (DB::getDriverName() === 'sqlite') {
            $sql .= ' ON CONFLICT(attempt_id, question_id) DO UPDATE SET response = excluded.response, flagged = excluded.flagged,'
                .' answered_at = excluded.answered_at, client_seq = excluded.client_seq WHERE excluded.client_seq > attempt_answers.client_seq';
        } else {
            // Order matters: client_seq is compared before it is raised.
            $newer = 'VALUES(client_seq) > client_seq';
            $sql .= " ON DUPLICATE KEY UPDATE response = IF($newer, VALUES(response), response), flagged = IF($newer, VALUES(flagged), flagged),"
                ." answered_at = IF($newer, VALUES(answered_at), answered_at), client_seq = GREATEST(client_seq, VALUES(client_seq))";
        }

        DB::statement($sql, $bindings);
    }

    /**
     * Heartbeat: records the client's status and any proctoring events, applies the
     * violation policy, and returns what the client must do now (Target 29-32, 70-76, 104-108).
     */
    public function heartbeat(Attempt $attempt, ?string $clientStatus, array $events = []): array
    {
        $this->expireIfDue($attempt);
        $policy = ExamSettings::forCandidate($attempt->exam->proctoring, $attempt->user_id, $attempt->exam_id);

        if ($attempt->isOpen()) {
            $newViolations = 0;
            $risk = 0;
            $rows = [];
            foreach (array_slice($events, 0, 50) as $e) {
                $type = substr(preg_replace('/[^a-z_]/', '', strtolower((string) ($e['type'] ?? ''))), 0, 40);
                if ($type === '') {
                    continue;
                }
                $severity = ExamSettings::severity($type);
                $rows[] = [
                    'tenant_id' => $attempt->tenant_id, 'attempt_id' => $attempt->id, 'type' => $type, 'severity' => $severity,
                    'data' => isset($e['data']) ? json_encode(array_slice((array) $e['data'], 0, 20)) : null,
                    'client_time' => isset($e['at']) ? Carbon::parse($e['at'])->format('Y-m-d H:i:s.v') : null,
                    'created_at' => now()->format('Y-m-d H:i:s.v'),
                ];
                $risk += ExamSettings::EVENT_WEIGHTS[$type] ?? 0;
                if (in_array($type, ExamSettings::VIOLATIONS, true)) {
                    $newViolations++;
                }
                if (in_array($type, ['multiple_faces', 'identity_mismatch', 'remote_desktop', 'vm_detected', 'face_absent', 'multiple_displays', 'wireless_display', 'camera_virtual', 'screen_capture_app'], true)) {
                    $this->flagForReview($attempt, $type, $severity);
                }
            }
            if ($rows) {
                ProctoringEvent::insert($rows);
            }

            $update = [
                'last_seen_at' => now()->format('Y-m-d H:i:s.v'),
                'client_status' => $clientStatus ? substr($clientStatus, 0, 16) : $attempt->client_status,
            ];
            if ($newViolations || $risk) {
                $update['violation_count'] = DB::raw('violation_count + '.$newViolations);
                $update['risk_score'] = DB::raw('LEAST(100, risk_score + '.min(100, $risk).')');
                if (DB::getDriverName() === 'sqlite') {
                    $update['risk_score'] = DB::raw('MIN(100, risk_score + '.min(100, $risk).')');
                }
            }
            DB::table('attempts')->where('id', $attempt->id)->update($update);
            if ($newViolations || $risk) {
                $attempt->forceFill((array) DB::table('attempts')->where('id', $attempt->id)->first(['violation_count', 'risk_score', 'status']));
            }
            $attempt->forceFill(['last_seen_at' => $update['last_seen_at'], 'client_status' => $update['client_status']])->syncOriginal();

            if ($newViolations && $attempt->violation_count >= $policy['violation_threshold'] && $attempt->status === 'in_progress') {
                match ($policy['violation_action']) {
                    'submit' => $this->submit($attempt, 'violations'),
                    'lock' => $this->lock($attempt, null, 'Too many security warnings.'),
                    default => $this->message($attempt, null, 'You have reached the warning limit. Further warnings are reported to the exams office.', 'warning'),
                };
                $attempt->forceFill((array) DB::table('attempts')->where('id', $attempt->id)->first(['status', 'submitted_at', 'violation_count']));
            }
        }

        $messages = CandidateMessage::where('attempt_id', $attempt->id)->where('direction', 'to_candidate')->whereNull('acknowledged_at')
            ->orderBy('id')->limit(10)->get(['id', 'kind', 'body', 'created_at']);

        return ['attempt' => $this->state($attempt), 'messages' => $messages];
    }

    public function submit(Attempt $attempt, string $reason = 'candidate'): Attempt
    {
        return DB::transaction(function () use ($attempt, $reason) {
            /** @var Attempt $locked */
            $locked = Attempt::whereKey($attempt->id)->lockForUpdate()->first();
            if (! $locked->isOpen()) {
                return $locked; // already submitted: duplicate submits are harmless (Target 135, 188)
            }

            $answers = DB::table('attempt_answers')->where('attempt_id', $locked->id)->orderBy('question_id')->get(['question_id', 'response']);
            $status = match ($reason) {
                'timeout', 'violations', 'session_closed' => 'auto_submitted',
                'terminated' => 'terminated',
                default => 'submitted',
            };
            $locked->forceFill([
                'status' => $status,
                'submit_reason' => $reason,
                'submitted_at' => now(),
                'paused_at' => null,
                'submission_hash' => hash('sha256', $locked->id.'|'.json_encode($answers)),
            ])->save();
            $this->event($locked, 'attempt_submitted', ['reason' => $reason, 'answers' => count($answers)], $reason === 'candidate' ? 1 : 3);

            $this->marker->markAttempt($locked);
            // recordings run for exactly as long as the candidate is writing
            DB::afterCommit(fn () => $this->stopRecordings($locked->id));

            return $locked->fresh();
        });
    }

    private function stopRecordings(int $attemptId): void
    {
        $live = app(\App\Services\Media\LiveKit::class);
        if (! $live->enabled()) {
            return;
        }
        foreach (\App\Models\MediaRecording::where('attempt_id', $attemptId)->whereIn('status', ['starting', 'active'])->get() as $rec) {
            try {
                $live->stopRecording($rec->egress_id);
                $rec->update(['status' => 'ending']);
            } catch (\Throwable) {
                // the media server finishes it by itself when the candidate's window closes
            }
        }
    }

    /** Close every attempt whose server deadline (+ grace) has passed. Run each minute and on every request. */
    public function sweepExpired(): int
    {
        $n = 0;
        Attempt::query()->withoutGlobalScopes()->where('status', 'in_progress')->where('deadline_at', '<', now()->subSeconds(30))
            ->orderBy('id')->limit(500)->get()->each(function (Attempt $a) use (&$n) {
                app(\App\Support\Tenancy\TenantContext::class)->run($a->tenant, function () use ($a, &$n) {
                    $this->submit($a, 'timeout');
                    $n++;
                });
            });

        return $n;
    }

    // ── Proctor / exams office actions ────────────────────────────────────────

    public function pause(Attempt $attempt, ?int $by): void
    {
        if ($attempt->status !== 'in_progress') {
            return;
        }
        $attempt->forceFill(['status' => 'paused', 'paused_at' => now()])->save();
        $this->event($attempt, 'paused', ['by' => $by], 2);
        $this->audit->record('attempt.paused', $attempt, [], $by);
    }

    public function resume(Attempt $attempt, ?int $by): void
    {
        if (! in_array($attempt->status, ['paused', 'locked'], true)) {
            return;
        }
        $paused = $attempt->paused_at ? (int) $attempt->paused_at->diffInSeconds(now()) : 0;
        $attempt->forceFill([
            'status' => 'in_progress',
            'paused_at' => null,
            'paused_seconds' => $attempt->paused_seconds + $paused,
            'deadline_at' => $attempt->deadline_at->copy()->addSeconds($paused), // paused time is given back
        ])->save();
        $this->event($attempt, 'resumed', ['by' => $by, 'paused_seconds' => $paused], 2);
        $this->audit->record('attempt.resumed', $attempt, ['paused_seconds' => $paused], $by);
    }

    public function lock(Attempt $attempt, ?int $by, string $reason): void
    {
        if (! $attempt->isOpen()) {
            return;
        }
        $attempt->forceFill(['status' => 'locked', 'paused_at' => $attempt->paused_at ?? now()])->save();
        $this->event($attempt, 'locked', ['by' => $by, 'reason' => $reason], 4);
        $this->message($attempt, $by, 'Your exam is locked: '.$reason.' Please wait for the invigilator.', 'warning');
        $this->audit->record('attempt.locked', $attempt, ['reason' => $reason], $by);
    }

    public function terminate(Attempt $attempt, int $by, string $reason): void
    {
        $this->submit($attempt, 'terminated');
        Incident::create([
            'exam_session_id' => $attempt->exam_session_id, 'attempt_id' => $attempt->id, 'type' => 'malpractice',
            'severity' => 'high', 'status' => 'investigating', 'source' => 'proctor', 'title' => 'Attempt terminated',
            'description' => $reason, 'opened_by' => $by,
        ]);
        $this->audit->record('attempt.terminated', $attempt, ['reason' => $reason], $by);
    }

    public function addTime(Attempt $attempt, int $minutes, int $by, string $reason): void
    {
        $attempt->forceFill(['deadline_at' => $attempt->deadline_at->copy()->addMinutes($minutes)])->save();
        $this->event($attempt, 'time_added', ['minutes' => $minutes, 'by' => $by], 2);
        $this->message($attempt, $by, "You have been given {$minutes} extra minute(s).", 'message');
        $this->audit->record('attempt.time_added', $attempt, ['minutes' => $minutes, 'reason' => $reason], $by);
    }

    public function message(Attempt $attempt, ?int $by, string $body, string $kind = 'message'): CandidateMessage
    {
        return CandidateMessage::create([
            'attempt_id' => $attempt->id, 'sender_id' => $by, 'direction' => 'to_candidate', 'kind' => $kind,
            'body' => mb_substr($body, 0, 2000), 'created_at' => now(),
        ]);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    public function state(Attempt $attempt): array
    {
        return [
            'id' => $attempt->id,
            'status' => $attempt->status,
            'remaining_seconds' => $attempt->remainingSeconds(),
            'deadline_at' => $attempt->deadline_at->toIso8601String(),
            'server_time' => now()->toIso8601String(),
            'answered' => $attempt->answered_count,
            'violations' => $attempt->violation_count,
            'session_paused' => (bool) $attempt->session?->paused_at,
        ];
    }

    public function expireIfDue(Attempt $attempt): void
    {
        if ($attempt->status === 'in_progress' && $attempt->deadline_at->copy()->addSeconds($attempt->session->grace_seconds ?? 30)->isPast()) {
            $this->submit($attempt, 'timeout');
            $attempt->refresh();
        }
    }

    private function guardWritable(Attempt $attempt): void
    {
        match (true) {
            in_array($attempt->status, Attempt::CLOSED, true) => throw new ExamException('This attempt has been submitted.', 'closed', 409),
            $attempt->status === 'paused' || (bool) $attempt->session?->paused_at => throw new ExamException('The exam is paused. Your answers are safe.', 'paused', 423),
            $attempt->status === 'locked' => throw new ExamException('Your exam is locked. Please wait for the invigilator.', 'locked', 423),
            default => null,
        };
    }

    private function guardOpenForReading(Attempt $attempt): void
    {
        if (in_array($attempt->status, Attempt::CLOSED, true)) {
            throw new ExamException('This attempt has been submitted.', 'closed', 409);
        }
    }

    /** @return array<int, string> question_id => type */
    /**
     * question id => [type, option ids] for this attempt. The paper is frozen when the attempt
     * starts, so this is cached until the attempt's deadline (one query per attempt, not per save).
     */
    private function questionMeta(Attempt $attempt): array
    {
        $build = fn () => DB::table('attempt_questions')->join('questions', 'questions.id', '=', 'attempt_questions.question_id')
            ->where('attempt_questions.attempt_id', $attempt->id)
            ->get(['questions.id', 'questions.type', 'attempt_questions.option_order'])
            ->mapWithKeys(fn ($r) => [$r->id => [$r->type, json_decode($r->option_order ?? '[]', true) ?: []]])->all();

        $meta = \Illuminate\Support\Facades\Cache::get("attempt:{$attempt->id}:meta");
        if (! is_array($meta) || ! $meta) {
            $meta = $build();
            if ($meta) {
                \Illuminate\Support\Facades\Cache::put("attempt:{$attempt->id}:meta", $meta, $attempt->deadline_at->copy()->addHour());
            }
        }

        return $meta;
    }

    /** @return array|null|false  normalised response, null = cleared, false = invalid */
    private function normaliseResponse(string $type, mixed $response, array $optionIds): array|null|false
    {
        if ($response === null || $response === [] || $response === '') {
            return null;
        }
        if (in_array($type, ['single_choice', 'multiple_choice', 'true_false'], true)) {
            $ids = array_values(array_unique(array_map('intval', (array) ($response['options'] ?? []))));
            if (! $ids) {
                return null;
            }
            if (array_diff($ids, array_map('intval', $optionIds)) || ($type !== 'multiple_choice' && count($ids) > 1)) {
                return false;
            }

            return ['options' => $ids];
        }
        if ($type === 'matching') {
            $pairs = [];
            foreach ((array) ($response['pairs'] ?? []) as $left => $right) {
                if ($right === null || $right === '') {
                    continue;
                }
                if (! preg_match('/^\d{1,3}$/', (string) $left) || ! in_array((string) $right, array_map('strval', $optionIds), true)) {
                    return false;
                }
                $pairs[(string) $left] = (string) $right;
            }

            return $pairs ? ['pairs' => $pairs] : null;
        }
        if ($type === 'numeric') {
            $n = str_replace(',', '.', (string) ($response['number'] ?? ''));

            return is_numeric($n) ? ['number' => (float) $n] : false;
        }
        $text = (string) ($response['text'] ?? '');

        return mb_strlen($text) > 100_000 ? false : ['text' => $text];
    }

    public function event(Attempt $attempt, string $type, array $data, int $severity): void
    {
        ProctoringEvent::insert([[
            'tenant_id' => $attempt->tenant_id, 'attempt_id' => $attempt->id, 'type' => $type, 'severity' => $severity,
            'data' => json_encode($data), 'client_time' => null, 'created_at' => now()->format('Y-m-d H:i:s.v'),
        ]]);
    }

    /** AI or rule alerts open an incident for a person to review; never a penalty by itself (Target 40, 111). */
    public function flagForReview(Attempt $attempt, string $type, int $severity): void
    {
        $exists = Incident::where('attempt_id', $attempt->id)->where('type', 'ai_alert')->where('title', 'like', $type.'%')
            ->whereIn('status', ['open', 'investigating', 'escalated'])->exists();
        if ($exists) {
            return;
        }
        Incident::create([
            'exam_session_id' => $attempt->exam_session_id, 'attempt_id' => $attempt->id, 'type' => 'ai_alert',
            'severity' => $severity >= 4 ? 'high' : 'medium', 'status' => 'open', 'source' => 'system',
            'needs_human_review' => true, 'title' => $type.' detected', 'description' => 'Raised automatically. A proctor must review the evidence before any action.',
        ]);
    }
}
