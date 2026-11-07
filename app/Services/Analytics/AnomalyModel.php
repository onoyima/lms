<?php

namespace App\Services\Analytics;

use App\Models\Attempt;
use App\Models\AttemptAnomaly;
use App\Models\Exam;
use Illuminate\Support\Facades\DB;

/**
 * Statistical anomaly review of a finished exam (Target 171). Each attempt is described by a few
 * measurable features, each feature is turned into a robust z-score against everyone else who
 * sat the same exam (median and MAD, so a few extreme candidates cannot hide themselves), and the
 * attempt is flagged when the combined score is unusually high. The model is unsupervised and
 * explainable: every flag lists the features that caused it. It never changes a mark or a result;
 * it only puts attempts in front of a person.
 *
 * Features (higher = more unusual):
 *  - speed_vs_score: scored much better than the time taken would predict (fit across the exam)
 *  - hard_items_fast: correct answers on the hardest items given in very little time
 *  - wrong_answer_overlap: most identical wrong answers shared with any one other candidate
 *  - late_burst: share of answers saved in the last 10% of the attempt
 *  - proctoring_risk: weighted proctoring events (already scored per attempt)
 */
class AnomalyModel
{
    public const MIN_ATTEMPTS = 8;
    public const THRESHOLD = 3.5;
    public const FEATURES = ['speed_vs_score', 'hard_items_fast', 'wrong_answer_overlap', 'late_burst', 'proctoring_risk'];

    public function run(Exam $exam): array
    {
        $attempts = Attempt::where('exam_id', $exam->id)->whereNotNull('submitted_at')->whereNotNull('started_at')->where('score_max', '>', 0)
            ->get(['id', 'started_at', 'submitted_at', 'score_raw', 'score_max', 'risk_score', 'paused_seconds']);
        if ($attempts->count() < self::MIN_ATTEMPTS) {
            return ['attempts' => $attempts->count(), 'flagged' => 0, 'skipped' => 'Too few attempts for a fair comparison (need '.self::MIN_ATTEMPTS.').'];
        }
        $ids = $attempts->pluck('id');
        $answers = DB::table('attempt_answers as aa')->join('attempt_questions as aq', fn ($j) => $j->on('aq.attempt_id', '=', 'aa.attempt_id')->on('aq.question_id', '=', 'aa.question_id'))
            ->whereIn('aa.attempt_id', $ids)->get(['aa.attempt_id', 'aa.question_id', 'aa.response', 'aa.score', 'aa.answered_at', 'aq.mark', 'aq.position']);
        $byAttempt = $answers->groupBy('attempt_id');

        // item difficulty: share of candidates scoring full marks
        $p = $answers->groupBy('question_id')->map(fn ($g) => $g->filter(fn ($a) => $a->score !== null && (float) $a->score >= (float) $a->mark)->count() / max(1, $g->count()));
        $hard = $p->filter(fn ($v) => $v <= 0.3)->keys()->flip();

        // shared identical wrong answers between pairs (rarely chosen wrong answers only)
        $wrong = [];
        foreach ($answers as $a) {
            if ($a->response !== null && $a->score !== null && (float) $a->score < (float) $a->mark) {
                $wrong[$a->question_id.'|'.$a->response][] = $a->attempt_id;
            }
        }
        $overlap = [];
        foreach ($wrong as $group) {
            $k = count($group);
            if ($k < 2 || $k > max(3, (int) ($attempts->count() * 0.15))) {
                continue;
            }
            foreach ($group as $x) {
                foreach ($group as $y) {
                    if ($x !== $y) {
                        $overlap[$x][$y] = ($overlap[$x][$y] ?? 0) + 1;
                    }
                }
            }
        }

        $raw = [];
        $durations = [];
        $scores = [];
        foreach ($attempts as $t) {
            $durations[$t->id] = max(1, $t->started_at->diffInSeconds($t->submitted_at) - (int) $t->paused_seconds);
            $scores[$t->id] = (float) $t->score_raw / (float) $t->score_max;
        }
        // least-squares fit of score on log(duration): the score expected for the time taken
        [$a, $b] = $this->fit(array_map('log', $durations), $scores);
        foreach ($attempts as $t) {
            $own = $byAttempt->get($t->id, collect());
            $span = $durations[$t->id];
            $times = $own->filter(fn ($x) => $x->answered_at)->sortBy('answered_at')->values();
            $perItem = $own->count() ? $span / $own->count() : $span;
            $fastHard = 0;
            $prev = $t->started_at;
            foreach ($times as $x) {
                $at = \Carbon\Carbon::parse($x->answered_at);
                $gap = max(0, $prev->diffInSeconds($at));
                $prev = $at;
                if (isset($hard[$x->question_id]) && (float) $x->score >= (float) $x->mark && $gap < max(10, $perItem * 0.25)) {
                    $fastHard++;
                }
            }
            $lateCut = $t->submitted_at->copy()->subSeconds((int) ($span * 0.1));
            $raw[$t->id] = [
                'speed_vs_score' => $scores[$t->id] - ($a + $b * log($span)),
                'hard_items_fast' => (float) $fastHard,
                'wrong_answer_overlap' => (float) (empty($overlap[$t->id]) ? 0 : max($overlap[$t->id])),
                'late_burst' => $own->count() ? $own->filter(fn ($x) => $x->answered_at && \Carbon\Carbon::parse($x->answered_at)->gte($lateCut))->count() / $own->count() : 0.0,
                'proctoring_risk' => (float) $t->risk_score,
            ];
        }

        $z = [];
        foreach (self::FEATURES as $f) {
            $col = array_column(array_map(fn ($r) => [$f => $r[$f]], $raw), $f);
            [$med, $mad] = $this->robust($col);
            foreach ($raw as $id => $r) {
                $z[$id][$f] = $mad > 0 ? ($r[$f] - $med) / (1.4826 * $mad) : ($r[$f] > $med ? 3.0 : 0.0);
            }
        }

        $flagged = 0;
        DB::transaction(function () use ($exam, $raw, $z, &$flagged) {
            foreach ($z as $id => $zs) {
                $pos = array_map(fn ($v) => max(0, min(10, $v)), $zs);
                $score = round(sqrt(array_sum(array_map(fn ($v) => $v * $v, $pos)) / count($pos)) * 2, 2);
                $reasons = collect($pos)->filter(fn ($v) => $v >= 2.5)->sortDesc()
                    ->map(fn ($v, $f) => ['feature' => $f, 'z' => round($v, 1), 'value' => round($raw[$id][$f], 3)])->values()->all();
                if ($score >= self::THRESHOLD && $reasons) {
                    $flagged++;
                    $row = AttemptAnomaly::firstOrNew(['attempt_id' => $id]);
                    $row->fill(['exam_id' => $exam->id, 'score' => $score, 'reasons' => $reasons] + ($row->exists ? [] : ['review' => 'open']))->save();
                } else {
                    AttemptAnomaly::where('attempt_id', $id)->where('review', 'open')->delete();
                }
            }
        });

        return ['attempts' => count($raw), 'flagged' => $flagged];
    }

    /** @return array{0: float, 1: float} intercept and slope */
    private function fit(array $x, array $y): array
    {
        $n = count($x);
        $mx = array_sum($x) / $n;
        $my = array_sum($y) / $n;
        $sxx = 0.0;
        $sxy = 0.0;
        foreach (array_keys($x) as $k) {
            $sxx += ($x[$k] - $mx) ** 2;
            $sxy += ($x[$k] - $mx) * ($y[$k] - $my);
        }
        $b = $sxx > 0 ? $sxy / $sxx : 0.0;

        return [$my - $b * $mx, $b];
    }

    /** @return array{0: float, 1: float} median and median absolute deviation */
    private function robust(array $v): array
    {
        sort($v);
        $med = $this->median($v);
        $dev = array_map(fn ($x) => abs($x - $med), $v);
        sort($dev);

        return [$med, $this->median($dev)];
    }

    private function median(array $sorted): float
    {
        $n = count($sorted);

        return $n === 0 ? 0.0 : ($n % 2 ? (float) $sorted[intdiv($n, 2)] : ($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2);
    }
}
