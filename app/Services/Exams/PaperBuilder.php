<?php

namespace App\Services\Exams;

use App\Models\Attempt;
use App\Models\AttemptPaper;
use App\Models\AttemptQuestion;
use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionVersion;
use App\Support\Exams\ExamSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds each candidate's own paper once, at the start of the attempt (Target 15-18, 23):
 *  - fixed questions + blueprint picks from the pool (topic / difficulty / type / count);
 *  - question and option shuffling per candidate;
 *  - the exact question version is recorded;
 *  - the candidate copy never contains correct answers; keys stay in attempt_questions.
 */
class PaperBuilder
{
    public function build(Attempt $attempt): array
    {
        $exam = $attempt->exam()->with(['sections.questions.options'])->firstOrFail();
        $settings = ExamSettings::settings($exam->settings);
        $seed = crc32($attempt->id.'|'.$attempt->user_id.'|'.config('app.key'));
        mt_srand($seed);

        $picked = collect();
        $sections = [];
        foreach ($exam->sections as $section) {
            $chosen = $section->questions->map(fn ($q) => [$q, $q->pivot->mark]);
            $draw = null;
            foreach ($section->blueprint ?? [] as $rule) {
                if (isset($rule['draw'])) {
                    // "Show N of this section's questions" (old portal: questions_to_show)
                    $draw = max(1, (int) $rule['draw']);

                    continue;
                }
                $exclude = $picked->keys()->merge($chosen->map(fn ($p) => $p[0]->id))->all();
                $pool = $this->pool($exam, $rule, $exclude);
                $need = (int) ($rule['count'] ?? 0);
                if ($pool->count() < $need) {
                    throw new RuntimeException("Section \"{$section->title}\" needs {$need} questions for rule ".json_encode($rule).", the pool has {$pool->count()}.");
                }
                foreach ($this->draw($pool, $rule, $need) as $q) {
                    $chosen->push([$q, $rule['mark'] ?? null]);
                }
            }
            if ($draw !== null && $chosen->count() > $draw) {
                $kept = $this->shuffle($chosen->all())->take($draw)->map(fn ($p) => $p[0]->id)->all();
                $chosen = $chosen->filter(fn ($p) => in_array($p[0]->id, $kept, true))->values();
            }
            if ($settings['shuffle_questions']) {
                $chosen = $this->keepPassagesTogether($this->shuffle($chosen->all()));
            }
            foreach ($chosen as [$q]) {
                $picked->put($q->id, true);
            }
            $sections[] = ['section' => $section, 'questions' => $chosen->values()];
        }

        if ($picked->isEmpty()) {
            throw new RuntimeException('This exam has no questions yet.');
        }

        return DB::transaction(function () use ($attempt, $sections, $settings, $exam) {
            $rows = [];
            $paper = ['exam' => ['title' => $exam->title, 'instructions' => $exam->instructions, 'type' => $exam->type], 'sections' => []];
            $position = 0;
            $maxScore = 0;

            foreach ($sections as ['section' => $section, 'questions' => $questions]) {
                $paperSection = ['id' => $section->id, 'title' => $section->title, 'instructions' => $section->instructions, 'answer_any' => $section->answer_any, 'questions' => []];
                foreach ($questions as [$q, $markOverride]) {
                    $position++;
                    $version = $this->ensureVersion($q);
                    $mark = (float) ($markOverride ?? $q->mark);
                    $maxScore += $mark;
                    $options = $q->options->values();
                    if ($settings['shuffle_options'] && in_array($q->type, ['single_choice', 'multiple_choice'], true)) {
                        $options = $this->shuffle($options->all());
                    }
                    if ($q->type === 'ordering' && $options->count() > 1) {
                        // the items are always mixed up, and never shown already in the right order
                        $right = $options->pluck('id')->all();
                        for ($try = 0; $try < 5 && $options->pluck('id')->all() === $right; $try++) {
                            $options = $this->shuffle($options->all());
                        }
                        if ($options->pluck('id')->all() === $right) {
                            $options = $options->reverse()->values();
                        }
                    }
                    $key = match ($q->type) {
                        'single_choice', 'multiple_choice', 'true_false' => ['options' => $q->options->where('is_correct', true)->pluck('id')->values()->all()] + ($q->scoring ? ['scoring' => $q->scoring] : []),
                        // multiple true/false: each statement is true or false on its own
                        'multi_true_false' => ['tf' => $q->options->mapWithKeys(fn ($o) => [(string) $o->id => (bool) $o->is_correct])->all()] + ($q->scoring ? ['scoring' => $q->scoring] : []),
                        'ordering' => ['order' => $q->options->sortBy('position')->pluck('id')->values()->all()],
                        'fill_blank' => ['blanks' => $q->accepted_answers['blanks'] ?? []],
                        'numeric' => $q->numeric_answer,
                        'matching' => ['pairs' => $q->matching['answer'] ?? []],
                        'short_answer' => $q->accepted_answers ?: null,
                        default => null,
                    };
                    $matching = null;
                    if ($q->type === 'matching') {
                        $right = $this->shuffle($q->matching['right'] ?? []);
                        $matching = ['left' => $q->matching['left'] ?? [], 'right' => $right->values()->all()];
                    }
                    $rows[] = [
                        'attempt_id' => $attempt->id, 'question_id' => $q->id, 'question_version' => $version,
                        'exam_section_id' => $section->id, 'position' => $position, 'mark' => $mark,
                        'option_order' => json_encode($q->type === 'matching' ? collect($matching['right'])->pluck('key')->all() : $options->pluck('id')->all()),
                        'key' => $key ? json_encode($key) : null,
                    ];
                    $paperSection['questions'][] = [
                        'id' => $q->id,
                        'number' => $position,
                        'type' => $q->type,
                        'mark' => $mark,
                        'stem_html' => $q->stem_html,
                        // label shown is by position (A, B, C...) so shuffling does not leak the original letter
                        'options' => $options->values()->map(fn ($o, $i) => ['id' => $o->id, 'label' => chr(65 + $i), 'html' => $o->body_html])->all(),
                        // matching: right-hand items are shuffled and their keys never reveal the answer
                        'matching' => $matching,
                        'blanks' => $q->type === 'fill_blank' ? Question::blankNumbers((string) $q->stem_html) : null,
                        'file' => $q->type === 'file_upload' ? array_replace(['kinds' => ['image', 'pdf', 'document'], 'max_mb' => 10], (array) ($q->accepted_answers['file'] ?? [])) : null,
                        'passage_id' => $q->passage_id,
                    ];
                    if ($q->passage_id && ! isset($paper['passages'][$q->passage_id])) {
                        $passage = \App\Models\QuestionPassage::find($q->passage_id);
                        $paper['passages'][$q->passage_id] = $passage ? ['title' => $passage->title, 'html' => $passage->body_html] : null;
                    }
                }
                $paper['sections'][] = $paperSection;
            }

            foreach (array_chunk($rows, 200) as $chunk) {
                AttemptQuestion::insert($chunk);
            }
            $json = json_encode($paper, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            AttemptPaper::create(['attempt_id' => $attempt->id, 'payload' => $json, 'checksum' => hash('sha256', $json)]);
            $attempt->forceFill(['score_max' => $maxScore])->save();

            return $paper;
        });
    }

    /**
     * Picks $need questions at random. With banks and "at least N from each", every bank gives its
     * minimum first (so every topic is covered), then the rest come from all the banks together.
     */
    private function draw(Collection $pool, array $rule, int $need): Collection
    {
        $min = (int) ($rule['min_each'] ?? 0);
        if (! $min || empty($rule['bank_ids'])) {
            return $this->shuffle($pool->all())->take($need);
        }
        $taken = collect();
        foreach ($rule['bank_ids'] as $bankId) {
            $this->shuffle($pool->where('question_bank_id', (int) $bankId)->all())->take($min)->each(fn ($q) => $taken->put($q->id, $q));
        }
        $rest = $this->shuffle($pool->reject(fn ($q) => $taken->has($q->id))->all())->take(max(0, $need - $taken->count()));

        return $this->shuffle($taken->values()->merge($rest)->all())->take($need);
    }

    /**
     * Problems that would stop a paper being built for a candidate, in words, so the setter sees them
     * before publishing: a rule asking for more questions than its banks or pool hold.
     *
     * @return list<string>
     */
    public function problems(Exam $exam): array
    {
        $out = [];
        $exam->loadMissing('sections.questions');
        foreach ($exam->sections as $section) {
            $fixed = $section->questions->pluck('id')->all();
            foreach ($section->blueprint ?? [] as $rule) {
                if (isset($rule['draw'])) {
                    continue;
                }
                $pool = $this->pool($exam, $rule, $fixed);
                $need = (int) ($rule['count'] ?? 0);
                $label = empty($rule['bank_ids']) ? 'the question pool' : 'the chosen banks';
                if ($pool->count() < $need) {
                    $out[] = "{$section->title}: a rule draws {$need} questions but {$label} have only {$pool->count()} that fit.";
                }
                $min = (int) ($rule['min_each'] ?? 0);
                if ($min && ! empty($rule['bank_ids'])) {
                    if ($min * count($rule['bank_ids']) > $need) {
                        $out[] = "{$section->title}: at least {$min} from each of ".count($rule['bank_ids'])." banks is more than the {$need} questions drawn.";
                    }
                    foreach (\App\Models\QuestionBank::whereIn('id', $rule['bank_ids'])->get() as $bank) {
                        $have = $pool->where('question_bank_id', $bank->id)->count();
                        $have < $min && $out[] = "{$section->title}: the bank \"{$bank->name}\" has {$have} questions; the rule needs at least {$min} from it (".($min - $have).' short).';
                    }
                }
            }
        }

        return $out;
    }

    /** Questions matching a blueprint rule from the exam's course pool, or from the chosen banks. Approved first; drafts only for practice. */
    private function pool(Exam $exam, array $rule, array $exclude): Collection
    {
        if (! empty($rule['bank_ids'])) {
            // an exam's banks are checked when the paper is approved, so their drafts count too
            return Question::query()->with('options')
                ->whereIn('question_bank_id', array_map('intval', $rule['bank_ids']))
                ->whereIn('status', ['approved', 'draft', 'in_review'])
                ->when($rule['topic'] ?? null, fn ($q, $v) => $q->where('topic', $v))
                ->when($rule['difficulty'] ?? null, fn ($q, $v) => $q->where('difficulty', $v))
                ->when($rule['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
                ->whereNotIn('id', $exclude ?: [0])
                ->get();
        }
        $statuses = $exam->category === 'practice' ? ['approved', 'draft', 'in_review'] : ['approved'];

        return Question::query()
            ->with('options')
            ->whereIn('status', $statuses)
            ->when($exam->course_id && ! ($rule['any_course'] ?? false), fn ($q) => $q->where('course_id', $exam->course_id))
            ->when($rule['topic'] ?? null, fn ($q, $v) => $q->where('topic', $v))
            ->when($rule['difficulty'] ?? null, fn ($q, $v) => $q->where('difficulty', $v))
            ->when($rule['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->whereNotIn('id', $exclude ?: [0])
            ->get();
    }

    private function ensureVersion(Question $q): int
    {
        QuestionVersion::firstOrCreate(
            ['question_id' => $q->id, 'version' => $q->version],
            ['snapshot' => $q->snapshot(), 'created_at' => now()]
        );

        return $q->version;
    }

    /** Deterministic per attempt (seeded), so a rebuild after a crash gives the same paper. */
    /** After shuffling, questions on the same passage stay together (in their set order), where the first of them fell. */
    private function keepPassagesTogether(Collection $chosen): Collection
    {
        $byPassage = $chosen->filter(fn ($p) => $p[0]->passage_id)->groupBy(fn ($p) => $p[0]->passage_id)
            ->map(fn ($g) => $g->sortBy(fn ($p) => $p[0]->pivot?->position ?? $p[0]->id)->values());
        $out = collect();
        $done = [];
        foreach ($chosen as $p) {
            $pid = $p[0]->passage_id;
            if (! $pid) {
                $out->push($p);
            } elseif (! isset($done[$pid])) {
                $done[$pid] = true;
                $byPassage[$pid]->each(fn ($x) => $out->push($x));
            }
        }

        return $out->values();
    }

    private function shuffle(array $items): Collection
    {
        $items = array_values($items);
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return collect($items)->values();
    }
}
