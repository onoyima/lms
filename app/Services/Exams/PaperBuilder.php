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
                foreach ($this->shuffle($pool->all())->take($need) as $q) {
                    $chosen->push([$q, $rule['mark'] ?? null]);
                }
            }
            if ($draw !== null && $chosen->count() > $draw) {
                $kept = $this->shuffle($chosen->all())->take($draw)->map(fn ($p) => $p[0]->id)->all();
                $chosen = $chosen->filter(fn ($p) => in_array($p[0]->id, $kept, true))->values();
            }
            if ($settings['shuffle_questions']) {
                $chosen = $this->shuffle($chosen->all());
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
                    $key = match ($q->type) {
                        'single_choice', 'multiple_choice', 'true_false' => ['options' => $q->options->where('is_correct', true)->pluck('id')->values()->all()],
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
                    ];
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

    /** Questions matching a blueprint rule from the exam's course pool. Approved first; drafts only for practice. */
    private function pool(Exam $exam, array $rule, array $exclude): Collection
    {
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
    private function shuffle(array $items): Collection
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return collect($items)->values();
    }
}
