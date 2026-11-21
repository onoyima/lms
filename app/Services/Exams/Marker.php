<?php

namespace App\Services\Exams;

use App\Models\Attempt;
use App\Models\CriterionMark;
use App\Models\Exam;
use App\Models\MarkingCriterion;
use App\Models\Result;
use App\Support\Exams\ExamSettings;
use Illuminate\Support\Facades\DB;

/**
 * Marking for every format (Target 59-61, 146-148):
 *  - objective questions are marked automatically from the server-side key;
 *  - essay / short answers wait for an examiner;
 *  - practical and viva scores come from criterion marks by one or more examiners,
 *    combined by the exam's marking policy (average, highest, first, median).
 * Then the candidate's result is (re)computed using the attempt score policy.
 */
class Marker
{
    public function markAttempt(Attempt $attempt): void
    {
        $exam = $attempt->exam;
        $settings = ExamSettings::settings($exam->settings);
        $questions = DB::table('attempt_questions')->join('questions', 'questions.id', '=', 'attempt_questions.question_id')
            ->where('attempt_questions.attempt_id', $attempt->id)
            ->get(['attempt_questions.question_id', 'attempt_questions.mark', 'attempt_questions.key', 'questions.type']);
        $answers = DB::table('attempt_answers')->where('attempt_id', $attempt->id)->get()->keyBy('question_id');

        $total = 0.0;
        $max = 0.0;
        $needsMarking = false;
        $scores = []; // written in one statement at the end (submits come in bursts at the deadline)

        foreach ($questions as $q) {
            $mark = (float) $q->mark;
            $answer = $answers[$q->question_id] ?? null;
            $response = $answer ? json_decode($answer->response ?? 'null', true) : null;
            $key = json_decode($q->key ?? 'null', true);
            // a faulty question settled by the examiners: taken out for everyone, or full marks for everyone
            if ($key['dropped'] ?? false) {
                $scores[$q->question_id] = 0.0;

                continue;
            }
            $max += $mark;
            if (($key['award'] ?? null) === 'all') {
                $total += $mark;
                $scores[$q->question_id] = $mark;

                continue;
            }

            $score = match ($q->type) {
                'single_choice', 'true_false' => $this->choice($response, $key, $mark, false, $settings),
                // a question's own scoring rule (from the old system's SCORING: line) wins over the exam's
                'multiple_choice' => $this->choice($response, $key, $mark, match ($key['scoring'] ?? null) { 'partial' => true, 'all' => false, default => $settings['partial_credit'] }, $settings),
                'multi_true_false' => $this->trueFalseSet($response, $key, $mark, match ($key['scoring'] ?? null) { 'partial' => ['mtf_scoring' => 'per_statement'] + $settings, 'all' => ['mtf_scoring' => 'all_or_nothing'] + $settings, default => $settings }),
                'ordering' => $this->ordering($response, $key, $mark, $settings['partial_credit']),
                'fill_blank' => $this->blanks($response, $key, $mark),
                'numeric' => $this->numeric($response, $key, $mark),
                'matching' => $this->matching($response, $key, $mark, $settings['partial_credit']),
                'short_answer' => $key && ! empty($key['answers']) ? $this->shortAnswer($response, $key, $mark) : null,
                default => null, // essay (and short answers without accepted answers): examiner marks it
            };

            if ($score === null) {
                if ($answer && $answer->score !== null) {
                    $total += (float) $answer->score; // already marked by an examiner
                } elseif ($response !== null) {
                    $needsMarking = true;
                } else {
                    // blank essay: zero, nothing to mark
                    $scores[$q->question_id] = 0.0;
                }

                continue;
            }

            $total += $score;
            $scores[$q->question_id] = $score;
        }
        $this->storeScores($attempt->id, $scores);

        $attempt->forceFill(['score_raw' => round(max(0, $total), 2), 'score_max' => $max, 'needs_marking' => $needsMarking])->save();
        $this->refreshResult($exam, $attempt->user_id);
    }

    private function choice(?array $response, ?array $key, float $mark, bool $partial, array $settings): float
    {
        $chosen = array_map('intval', $response['options'] ?? []);
        $correct = array_map('intval', $key['options'] ?? []);
        if (! $chosen) {
            return 0.0;
        }
        sort($chosen);
        sort($correct);
        if ($chosen === $correct) {
            return $mark;
        }
        if ($partial && $correct) {
            $right = count(array_intersect($chosen, $correct));
            $wrong = count(array_diff($chosen, $correct));

            return max(0, round($mark * ($right - $wrong) / count($correct), 2));
        }

        return -abs((float) $settings['negative_marking']);
    }

    /**
     * Multiple true/false: each statement is worth an equal share of the mark. A wrong statement loses
     * the exam's negative mark shared the same way; a statement left blank scores nothing. With
     * "all or nothing" the question scores only when every statement is right.
     */
    private function trueFalseSet(?array $response, ?array $key, float $mark, array $settings): float
    {
        $answer = (array) ($key['tf'] ?? []);
        $given = (array) ($response['tf'] ?? []);
        if (! $answer || ! $given) {
            return 0.0;
        }
        $n = count($answer);
        $right = 0;
        $wrong = 0;
        foreach ($answer as $id => $truth) {
            if (! array_key_exists((string) $id, $given)) {
                continue;
            }
            (bool) $given[(string) $id] === (bool) $truth ? $right++ : $wrong++;
        }
        if (($settings['mtf_scoring'] ?? 'per_statement') === 'all_or_nothing') {
            return $right === $n ? $mark : ($wrong ? -abs((float) $settings['negative_marking']) : 0.0);
        }

        return round(max(-$mark, $mark * $right / $n - abs((float) $settings['negative_marking']) * $wrong / $n), 2);
    }

    /** Ordering: all in the right order for the mark; with partial credit, each item in its right place earns its share. */
    private function ordering(?array $response, ?array $key, float $mark, bool $partial): float
    {
        $right = array_map('intval', (array) ($key['order'] ?? []));
        $given = array_map('intval', (array) ($response['order'] ?? []));
        if (! $right || ! $given) {
            return 0.0;
        }
        if ($given === $right) {
            return $mark;
        }
        if (! $partial) {
            return 0.0;
        }
        $hits = count(array_filter($right, fn ($id, $i) => ($given[$i] ?? null) === $id, ARRAY_FILTER_USE_BOTH));

        return round($mark * $hits / count($right), 2);
    }

    /**
     * Fill in the blanks: each blank earns an equal share. A blank is right when it matches one of its
     * accepted answers (spaces, case and a trailing full stop ignored unless case-sensitive), or is a
     * number within the blank's tolerance.
     */
    private function blanks(?array $response, ?array $key, float $mark): float
    {
        $blanks = array_values((array) ($key['blanks'] ?? []));
        $given = (array) ($response['blanks'] ?? []);
        if (! $blanks || ! $given) {
            return 0.0;
        }
        $norm = fn ($s) => preg_replace('/\s+/u', ' ', trim(rtrim(trim((string) $s), '.')));
        $right = 0;
        foreach ($blanks as $i => $b) {
            $answer = $given[(string) ($i + 1)] ?? null;
            if ($answer === null || trim((string) $answer) === '') {
                continue;
            }
            if (isset($b['value']) && is_numeric(str_replace(',', '.', (string) $answer))) {
                if (abs((float) str_replace(',', '.', (string) $answer) - (float) $b['value']) <= abs((float) ($b['tolerance'] ?? 0)) + 1e-9) {
                    $right++;
                }

                continue;
            }
            foreach ((array) ($b['answers'] ?? []) as $ok) {
                if (! empty($b['case_sensitive']) ? $norm($answer) === $norm($ok) : mb_strtolower($norm($answer)) === mb_strtolower($norm($ok))) {
                    $right++;
                    break;
                }
            }
        }

        return round($mark * $right / count($blanks), 2);
    }

    /** Matching: each correct pair earns its share (or all-or-nothing when partial credit is off). */
    private function matching(?array $response, ?array $key, float $mark, bool $partial): float
    {
        $answer = (array) ($key['pairs'] ?? []);
        if (! $answer || ! $response) {
            return 0.0;
        }
        $given = (array) ($response['pairs'] ?? []);
        $right = count(array_filter($answer, fn ($r, $l) => isset($given[$l]) && (string) $given[$l] === (string) $r, ARRAY_FILTER_USE_BOTH));

        return $partial ? round($mark * $right / count($answer), 2) : ($right === count($answer) ? $mark : 0.0);
    }

    /** Short answer: full marks for any accepted answer (spaces, case and trailing punctuation ignored unless case-sensitive). */
    private function shortAnswer(?array $response, array $key, float $mark): float
    {
        $norm = fn ($s) => preg_replace('/\s+/u', ' ', trim(rtrim((string) $s, ". \t\n"), " \t\n"));
        $given = $norm(strip_tags((string) ($response['text'] ?? '')));
        if ($given === '') {
            return 0.0;
        }
        foreach ((array) $key['answers'] as $a) {
            $a = $norm($a);
            if (($key['case_sensitive'] ?? false) ? $given === $a : mb_strtolower($given) === mb_strtolower($a)) {
                return $mark;
            }
        }

        return 0.0;
    }

    private function numeric(?array $response, ?array $key, float $mark): float
    {
        if (! isset($response['number'], $key['value'])) {
            return 0.0;
        }
        $tolerance = abs((float) ($key['tolerance'] ?? 0));

        return abs((float) $response['number'] - (float) $key['value']) <= $tolerance + 1e-9 ? $mark : 0.0;
    }

    /** All of an attempt's automatic scores in one statement; unanswered questions get a row with no response. */
    private function storeScores(int $attemptId, array $scores): void
    {
        if (! $scores) {
            return;
        }
        $rows = [];
        foreach ($scores as $qid => $score) {
            $rows[] = ['attempt_id' => $attemptId, 'question_id' => (int) $qid, 'response' => null, 'client_seq' => 0, 'score' => $score];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('attempt_answers')->upsert($chunk, ['attempt_id', 'question_id'], ['score']);
        }
    }

    /** An examiner marks an essay/short answer, then the attempt total is recomputed. */
    public function markAnswer(Attempt $attempt, int $questionId, float $score, int $examinerId, ?string $feedback): void
    {
        $max = (float) DB::table('attempt_questions')->where('attempt_id', $attempt->id)->where('question_id', $questionId)->value('mark');
        abort_if($score < 0 || $score > $max, 422, "Score must be between 0 and $max.");

        DB::table('attempt_answers')->updateOrInsert(
            ['attempt_id' => $attempt->id, 'question_id' => $questionId],
            ['score' => $score, 'marked_by' => $examinerId, 'marked_at' => now(), 'feedback' => $feedback ? mb_substr($feedback, 0, 5000) : null]
        );
        $this->markAttempt($attempt->fresh());
    }

    /** Practical / viva (legacy assess.php): one examiner's scores for one candidate. */
    public function recordCriterionMarks(Exam $exam, int $candidateId, int $examinerId, array $scores): void
    {
        $criteria = $exam->criteria()->get()->keyBy('id');
        DB::transaction(function () use ($exam, $candidateId, $examinerId, $scores, $criteria) {
            foreach ($scores as $criterionId => $row) {
                /** @var MarkingCriterion|null $criterion */
                $criterion = $criteria[(int) $criterionId] ?? null;
                $score = is_array($row) ? ($row['score'] ?? null) : $row;
                if (! $criterion || $score === null || $score === '') {
                    continue;
                }
                $score = (float) $score;
                abort_unless($criterion->accepts($score), 422, "\"{$criterion->text}\" does not accept a score of $score.");
                CriterionMark::updateOrCreate(
                    ['marking_criterion_id' => $criterion->id, 'user_id' => $candidateId, 'examiner_id' => $examinerId],
                    ['exam_id' => $exam->id, 'score' => $score, 'comment' => is_array($row) ? ($row['comment'] ?? null) : null]
                );
            }
        });
        $this->refreshResult($exam, $candidateId);
    }

    /** Combine examiners' marks per criterion according to the exam's marking policy. */
    public function panelScore(Exam $exam, int $candidateId): array
    {
        $criteria = $exam->criteria()->get();
        $marks = CriterionMark::where('exam_id', $exam->id)->where('user_id', $candidateId)->orderBy('id')->get()->groupBy('marking_criterion_id');
        $total = 0.0;
        $marked = 0;
        foreach ($criteria as $c) {
            $scores = $marks->get($c->id, collect())->pluck('score')->map(fn ($s) => (float) $s)->all();
            if (! $scores) {
                continue;
            }
            $marked++;
            sort($scores);
            $total += match ($exam->marking_policy) {
                'highest' => max($scores),
                'first' => $marks->get($c->id)->first()->score,
                'median' => count($scores) % 2 ? $scores[intdiv(count($scores), 2)] : ($scores[count($scores) / 2 - 1] + $scores[count($scores) / 2]) / 2,
                default => array_sum($scores) / count($scores),
            };
        }

        return ['raw' => round($total, 2), 'max' => (float) $criteria->sum('max_score'), 'complete' => $marked === $criteria->count() && $marked > 0, 'marked' => $marked];
    }

    /** Recompute one candidate's result from their attempts (or panel marks). Locked results are never overwritten. */
    public function refreshResult(Exam $exam, int $userId): ?Result
    {
        $result = Result::firstOrNew(['exam_id' => $exam->id, 'user_id' => $userId]);
        if ($result->locked) {
            return $result;
        }

        if ($exam->isPanelMarked()) {
            $panel = $this->panelScore($exam, $userId);
            $raw = $panel['raw'];
            $max = $panel['max'];
            $pending = ! $panel['complete'];
            $attemptId = null;
        } else {
            $attempts = Attempt::where('exam_id', $exam->id)->where('user_id', $userId)->whereIn('status', Attempt::CLOSED)->orderBy('attempt_no')->get();
            if ($attempts->isEmpty()) {
                return null;
            }
            $ready = $attempts->where('needs_marking', false);
            $pending = $ready->count() !== $attempts->count();
            $pool = $ready->isNotEmpty() ? $ready : $attempts;
            $chosen = match ($exam->score_policy) {
                'first' => $pool->first(),
                'last' => $pool->last(),
                default => $pool->sortByDesc(fn ($a) => $a->score_max > 0 ? $a->score_raw / $a->score_max : 0)->first(),
            };
            $raw = $exam->score_policy === 'average' ? round($pool->avg('score_raw'), 2) : (float) $chosen->score_raw;
            $max = (float) $chosen->score_max;
            $attemptId = $chosen->id;
        }

        $final = $exam->total_mark && $max > 0 ? round($raw / $max * (float) $exam->total_mark, 2) : $raw;
        $outOf = $exam->total_mark ? (float) $exam->total_mark : $max;
        // resit results can be capped at a grade set in the institution policy (e.g. C)
        if ($exam->kind === 'resit' && ($cap = \App\Support\Policy::get('resit_max_grade')) && $outOf > 0) {
            $capPct = (float) (\App\Support\Policy::get('grading_scale')[$cap] ?? 100);
            $final = min($final, round($capPct / 100 * $outOf, 2));
        }

        $result->fill([
            'attempt_id' => $attemptId,
            'raw_score' => $raw,
            'max_score' => $max,
            'final_score' => $final,
            'grade' => $this->grade($outOf > 0 ? $final / $outOf * 100 : 0, data_get($exam->settings, 'grading_scale') ?: null),
            'status' => in_array($result->status, ['moderated', 'approved', 'published', 'withheld'], true) && $result->exists ? $result->status : ($pending ? 'pending_marking' : 'draft'),
        ]);
        if ($result->isDirty() && $result->exists) {
            $result->version++;
        }
        $result->save();

        return $result;
    }

    /** Nigerian university 5-point scale by default; institutions can override in tenant settings. */
    public function grade(float $percent, ?string $scale = null): string
    {
        return \App\Support\Policy::grade($percent, $scale);
    }
}
