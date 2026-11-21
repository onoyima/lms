<?php

namespace App\Services\Analytics;

use App\Models\Attempt;
use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionReport;
use App\Models\Result;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Statistics per question, as the old exam system showed them, from the scripts that count:
 * how many had it, answered it, got full marks, the average mark and the facility (average as a
 * share of the mark), how often each option was chosen, and for multiple true/false how often each
 * statement was judged right. Also the per-question score sheet (Q1…Qn) for download.
 */
class QuestionStats
{
    /** The scripts that count: each student's attempt behind their result, or their last closed attempt. */
    public function attempts(Exam $exam): Collection
    {
        $closed = Attempt::where('exam_id', $exam->id)->whereIn('status', Attempt::CLOSED)->with('user:id,name,identifier,email')->orderBy('attempt_no')->get();
        $counted = Result::where('exam_id', $exam->id)->whereNotNull('attempt_id')->pluck('attempt_id', 'user_id');

        return $closed->groupBy('user_id')->map(fn ($list, $uid) => $list->firstWhere('id', $counted[$uid] ?? 0) ?? $list->last())->values();
    }

    /** Questions in paper order (the order most students met them), numbered Q1, Q2… @return Collection<int, array> */
    public function questions(Exam $exam, ?Collection $attempts = null): Collection
    {
        $attempts ??= $this->attempts($exam);
        $order = DB::table('attempt_questions')->whereIn('attempt_id', $attempts->pluck('id')->all() ?: [0])
            ->selectRaw('question_id, AVG(position) p, MAX(mark) mark, COUNT(*) seen')->groupBy('question_id')->orderBy('p')->orderBy('question_id')->get();
        if ($order->isEmpty()) {
            // nobody has written yet: the fixed questions, in the paper's order
            $exam->loadMissing('sections.questions');
            $order = $exam->sections->flatMap(fn ($s) => $s->questions)->values()->map(fn ($q, $i) => (object) ['question_id' => $q->id, 'p' => $i, 'mark' => $q->pivot->mark ?? $q->mark, 'seen' => 0]);
        }
        $models = Question::withTrashed()->with('options')->whereIn('id', $order->pluck('question_id'))->get()->keyBy('id');

        return $order->values()->map(fn ($o, $i) => ['n' => $i + 1, 'id' => (int) $o->question_id, 'mark' => (float) $o->mark, 'seen' => (int) $o->seen, 'question' => $models[$o->question_id] ?? null]);
    }

    public function stats(Exam $exam): array
    {
        $attempts = $this->attempts($exam);
        $questions = $this->questions($exam, $attempts);
        $rows = DB::table('attempt_questions as aq')
            ->leftJoin('attempt_answers as aa', fn ($j) => $j->on('aa.attempt_id', '=', 'aq.attempt_id')->on('aa.question_id', '=', 'aq.question_id'))
            ->whereIn('aq.attempt_id', $attempts->pluck('id')->all() ?: [0])
            ->get(['aq.question_id', 'aq.mark', 'aq.key', 'aa.response', 'aa.score'])->groupBy('question_id');
        $items = collect(app(ExamAnalytics::class)->itemAnalysisCached($exam))->keyBy('question_id');
        $reports = QuestionReport::where('exam_id', $exam->id)->selectRaw('question_id, COUNT(*) n')->groupBy('question_id')->pluck('n', 'question_id');

        return $questions->map(function ($q) use ($rows, $items, $reports) {
            $list = $rows[$q['id']] ?? collect();
            $model = $q['question'];
            $seen = $list->count();
            $answered = $list->filter(fn ($r) => self::answered(json_decode($r->response ?? 'null', true)))->count();
            $full = $list->filter(fn ($r) => (float) $r->mark > 0 && (float) $r->score >= (float) $r->mark - 1e-9)->count();
            $avg = $seen ? $list->avg(fn ($r) => (float) ($r->score ?? 0)) : null;

            $options = [];
            if ($model && in_array($model->type, ['single_choice', 'multiple_choice', 'true_false'], true)) {
                $chosen = [];
                foreach ($list as $r) {
                    foreach ((array) (json_decode($r->response ?? 'null', true)['options'] ?? []) as $id) {
                        $chosen[(int) $id] = ($chosen[(int) $id] ?? 0) + 1;
                    }
                }
                $key = $list->first() ? (array) (json_decode($list->first()->key ?? 'null', true)['options'] ?? []) : [];
                foreach ($model->options as $i => $o) {
                    $options[] = ['label' => chr(65 + $i), 'html' => $o->body_html, 'correct' => in_array($o->id, $key ?: ($o->is_correct ? [$o->id] : []), true) || (! $key && $o->is_correct),
                        'count' => $chosen[$o->id] ?? 0, 'percent' => $seen ? round(($chosen[$o->id] ?? 0) / $seen * 100, 1) : 0];
                }
            }
            $statements = [];
            if ($model?->type === 'multi_true_false') {
                foreach ($model->options as $i => $o) {
                    $right = 0;
                    foreach ($list as $r) {
                        $truth = (array) (json_decode($r->key ?? 'null', true)['tf'] ?? []);
                        $given = (array) (json_decode($r->response ?? 'null', true)['tf'] ?? []);
                        if (array_key_exists((string) $o->id, $given) && (bool) $given[(string) $o->id] === (bool) ($truth[(string) $o->id] ?? $o->is_correct)) {
                            $right++;
                        }
                    }
                    $statements[] = ['label' => chr(97 + $i), 'html' => $o->body_html, 'true' => (bool) $o->is_correct, 'percent' => $seen ? round($right / $seen * 100, 1) : 0];
                }
            }
            $item = $items[$q['id']] ?? null;

            return [
                'n' => $q['n'], 'id' => $q['id'], 'type' => $model?->type, 'stem_html' => $model?->stem_html, 'topic' => $model?->topic, 'mark' => $q['mark'],
                'seen' => $seen, 'answered' => $answered, 'full_marks' => $full,
                'full_percent' => $seen ? round($full / $seen * 100, 1) : null,
                'average' => $avg !== null ? round($avg, 2) : null,
                'facility' => $avg !== null && $q['mark'] > 0 ? round($avg / $q['mark'] * 100, 1) : null,
                'discrimination' => $item['discrimination'] ?? null, 'flag' => $item['flag'] ?? null,
                'options' => $options, 'statements' => $statements, 'reports' => (int) ($reports[$q['id']] ?? 0),
            ];
        })->values()->all();
    }

    /**
     * The old system's per-question sheet: names split, matric, Q1…Qn (blank where a student did not
     * have the question), total, out of, percentage, time taken and status.
     *
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    public function scoreSheet(Exam $exam): array
    {
        $attempts = $this->attempts($exam);
        $questions = $this->questions($exam, $attempts);
        $scores = DB::table('attempt_questions as aq')
            ->leftJoin('attempt_answers as aa', fn ($j) => $j->on('aa.attempt_id', '=', 'aq.attempt_id')->on('aa.question_id', '=', 'aq.question_id'))
            ->whereIn('aq.attempt_id', $attempts->pluck('id')->all() ?: [0])
            ->get(['aq.attempt_id', 'aq.question_id', 'aq.key', 'aa.score'])->groupBy('attempt_id');
        $results = Result::where('exam_id', $exam->id)->get()->keyBy('user_id');

        $head = ['First Name', 'Middle Name', 'Last Name', 'Username', 'Matric'];
        foreach ($questions as $q) {
            $head[] = 'Q'.$q['n'].($q['question']?->type === 'multi_true_false' ? ' (MTF)' : '');
        }
        array_push($head, 'Total', 'Out of', 'Percentage', 'Final score', 'Grade', 'Time taken (min)', 'Status');

        $rows = [];
        foreach ($attempts->sortBy(fn ($a) => mb_strtolower((string) $a->user?->name))->values() as $a) {
            [$first, $middle, $last] = self::nameParts((string) $a->user?->name);
            $mine = ($scores[$a->id] ?? collect())->keyBy('question_id');
            $line = [$first, $middle, $last, $a->user?->email && ! str_ends_with($a->user->email, '.invalid') ? $a->user->email : '', $a->user?->identifier];
            foreach ($questions as $q) {
                $cell = $mine[$q['id']] ?? null;
                $line[] = $cell === null ? '' : (json_decode($cell->key ?? 'null', true)['dropped'] ?? false ? 'dropped' : round((float) ($cell->score ?? 0), 2));
            }
            $r = $results[$a->user_id] ?? null;
            $minutes = $a->started_at && $a->submitted_at ? round(max(0, $a->submitted_at->diffInSeconds($a->started_at, true) - (int) $a->paused_seconds) / 60, 1) : '';
            array_push($line, round((float) $a->score_raw, 2), round((float) $a->score_max, 2), $a->score_max > 0 ? round($a->score_raw / $a->score_max * 100, 1) : '',
                $r?->final_score !== null ? (float) $r->final_score : '', $r?->grade ?? '', $minutes, str_replace('_', ' ', $a->status));
            $rows[] = $line;
        }

        return [$head, $rows];
    }

    /** "Surname Firstname Middle" or "Firstname Middle Surname": first word, the middle, last word. */
    public static function nameParts(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        if (count($parts) <= 1) {
            return [$parts[0] ?? '', '', ''];
        }
        $first = array_shift($parts);
        $last = array_pop($parts);

        return [$first, implode(' ', $parts), $last];
    }

    private static function answered(mixed $r): bool
    {
        if ($r === null || $r === '' || $r === []) {
            return false;
        }
        if (is_array($r)) {
            foreach ($r as $v) {
                if (is_array($v) ? $v !== [] : ($v !== null && $v !== '')) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }
}
