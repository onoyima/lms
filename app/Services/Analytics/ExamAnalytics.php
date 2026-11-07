<?php

namespace App\Services\Analytics;

use App\Models\Attempt;
use App\Models\Exam;
use App\Models\ExamSession;
use App\Models\ItemStatistic;
use Illuminate\Support\Facades\DB;

/**
 * Item analysis, collusion signals and reports (Target 62-69, 159-160, 192-197).
 * Everything is computed with a few grouped queries per exam, so it stays fast with
 * thousands of candidates; results are stored in item_statistics.
 */
class ExamAnalytics
{
    /** Difficulty (p), discrimination (upper-lower 27%) and point-biserial per question. */
    public function itemAnalysis(Exam $exam): array
    {
        $attempts = Attempt::where('exam_id', $exam->id)->whereIn('status', Attempt::CLOSED)
            ->whereNotNull('score_raw')->get(['id', 'score_raw', 'score_max']);
        if ($attempts->count() < 2) {
            return [];
        }

        $scores = $attempts->mapWithKeys(fn ($a) => [$a->id => $a->score_max > 0 ? $a->score_raw / $a->score_max : 0]);
        $sorted = $scores->sort()->keys();
        $n = $sorted->count();
        $g = max(1, (int) round($n * 0.27));
        $lower = $sorted->take($g)->flip();
        $upper = $sorted->slice($n - $g)->flip();
        $mean = $scores->avg();
        $sd = sqrt($scores->map(fn ($s) => ($s - $mean) ** 2)->sum() / $n) ?: 1e-9;

        $rows = DB::table('attempt_answers as aa')
            ->join('attempt_questions as aq', fn ($j) => $j->on('aq.attempt_id', '=', 'aa.attempt_id')->on('aq.question_id', '=', 'aa.question_id'))
            ->whereIn('aa.attempt_id', $attempts->pluck('id'))
            ->get(['aa.attempt_id', 'aa.question_id', 'aa.score', 'aa.response', 'aq.mark']);

        $out = [];
        foreach ($rows->groupBy('question_id') as $qid => $answers) {
            $correct = $answers->filter(fn ($a) => $a->mark > 0 && (float) $a->score >= (float) $a->mark - 1e-9);
            $p = $answers->count() ? $correct->count() / $answers->count() : 0;
            $pu = $correct->filter(fn ($a) => isset($upper[$a->attempt_id]))->count() / $g;
            $pl = $correct->filter(fn ($a) => isset($lower[$a->attempt_id]))->count() / $g;

            $mc = $correct->map(fn ($a) => $scores[$a->attempt_id])->avg();
            $wrong = $answers->reject(fn ($a) => $correct->contains('attempt_id', $a->attempt_id));
            $mw = $wrong->map(fn ($a) => $scores[$a->attempt_id])->avg();
            $pb = ($correct->isNotEmpty() && $wrong->isNotEmpty()) ? ($mc - $mw) / $sd * sqrt($p * (1 - $p)) : null;

            $optionCounts = [];
            foreach ($answers as $a) {
                foreach (json_decode($a->response ?? 'null', true)['options'] ?? [] as $opt) {
                    $optionCounts[$opt] = ($optionCounts[$opt] ?? 0) + 1;
                }
            }

            $stat = ItemStatistic::updateOrCreate(['exam_id' => $exam->id, 'question_id' => $qid], [
                'responses' => $answers->count(), 'difficulty' => round($p, 4), 'discrimination' => round($pu - $pl, 4),
                'point_biserial' => $pb === null ? null : round(max(-1, min(1, $pb)), 4), 'option_counts' => $optionCounts, 'computed_at' => now(),
            ]);
            $out[] = $stat->toArray() + ['flag' => $this->flag($p, $pu - $pl)];
        }

        return $out;
    }

    private function flag(float $p, float $d): ?string
    {
        return match (true) {
            $d < 0 => 'Negative discrimination — check the key',
            $p > 0.95 => 'Very easy',
            $p < 0.2 => 'Very hard — check the key or teaching',
            $d < 0.2 => 'Weak discrimination',
            default => null,
        };
    }

    /**
     * Pairs of candidates in the same sitting who share an unusual number of identical
     * wrong answers (Target 69). Signals only: a person must review before any action.
     */
    public function suspiciousPairs(ExamSession $session, int $minShared = 5): array
    {
        $rows = DB::table('attempt_answers as aa')->join('attempts as a', 'a.id', '=', 'aa.attempt_id')
            ->where('a.exam_session_id', $session->id)->whereNotNull('aa.response')
            ->whereRaw('aa.score < (SELECT aq.mark FROM attempt_questions aq WHERE aq.attempt_id = aa.attempt_id AND aq.question_id = aa.question_id)')
            ->get(['aa.attempt_id', 'aa.question_id', 'aa.response']);

        $groups = [];
        foreach ($rows as $r) {
            $groups[$r->question_id.'|'.$r->response][] = $r->attempt_id;
        }
        $pairs = [];
        foreach ($groups as $ids) {
            $k = count($ids);
            if ($k < 2 || $k > 200) {
                continue; // a wrong answer chosen by very many is a bad item, not collusion
            }
            for ($i = 0; $i < $k; $i++) {
                for ($j = $i + 1; $j < $k; $j++) {
                    $key = min($ids[$i], $ids[$j]).'-'.max($ids[$i], $ids[$j]);
                    $pairs[$key] = ($pairs[$key] ?? 0) + 1;
                }
            }
        }
        arsort($pairs);
        $flagged = array_filter($pairs, fn ($c) => $c >= $minShared);
        $attempts = Attempt::whereIn('id', collect(array_keys($flagged))->flatMap(fn ($k) => explode('-', $k)))->with('user:id,name,identifier', 'candidate.seat')->get()->keyBy('id');

        return collect($flagged)->take(50)->map(function ($count, $key) use ($attempts) {
            [$a, $b] = explode('-', $key);

            return [
                'shared_wrong' => $count,
                'a' => ['attempt' => (int) $a, 'name' => $attempts[$a]?->user?->name, 'seat' => $attempts[$a]?->candidate?->seat?->label],
                'b' => ['attempt' => (int) $b, 'name' => $attempts[$b]?->user?->name, 'seat' => $attempts[$b]?->candidate?->seat?->label],
            ];
        })->values()->all();
    }

    /** Attendance and submission report for a sitting (Target 63-64, 192, 194). */
    public function sessionReport(ExamSession $session): array
    {
        $c = DB::table('exam_candidates')->where('exam_session_id', $session->id)
            ->selectRaw("COUNT(*) total, SUM(attendance='present') present, SUM(attendance='absent') absent, SUM(attendance='excused') excused, SUM(eligibility='ineligible') ineligible")->first();
        $a = DB::table('attempts')->where('exam_session_id', $session->id)
            ->selectRaw("COUNT(*) attempts, SUM(status='submitted') submitted, SUM(status='auto_submitted') auto_submitted, SUM(status='terminated') AS `terminated`, SUM(status IN ('in_progress','paused','locked')) AS `open`, AVG(score_raw) avg_score, AVG(violation_count) avg_violations")->first();
        $i = DB::table('incidents')->where('exam_session_id', $session->id)->selectRaw('severity, COUNT(*) n')->groupBy('severity')->pluck('n', 'severity');

        return ['candidates' => (array) $c, 'attempts' => (array) $a, 'incidents' => $i->all()];
    }

    /** Performance by department / faculty for an exam or course (Target 195-197). */
    public function performanceByDepartment(Exam $exam): array
    {
        return DB::table('results as r')->join('users as u', 'u.id', '=', 'r.user_id')
            ->leftJoin('enrolments as en', 'en.user_id', '=', 'u.id')
            ->leftJoin('course_offerings as co', 'co.id', '=', 'en.course_offering_id')
            ->leftJoin('courses as c', 'c.id', '=', 'co.course_id')
            ->leftJoin('departments as d', 'd.id', '=', 'c.department_id')
            ->where('r.exam_id', $exam->id)
            ->groupBy('d.name')
            ->selectRaw("COALESCE(d.name, 'Unassigned') department, COUNT(DISTINCT r.id) candidates, AVG(r.final_score) average, SUM(r.grade <> 'F') passed")
            ->get()->all();
    }
}
