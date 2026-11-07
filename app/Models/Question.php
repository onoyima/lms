<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Question extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const TYPES = ['single_choice', 'multiple_choice', 'true_false', 'matching', 'numeric', 'short_answer', 'essay'];

    protected $fillable = [
        'course_id', 'question_import_id', 'created_by', 'type', 'stem_html', 'explanation_html',
        'topic', 'difficulty', 'mark', 'status', 'version', 'numeric_answer', 'matching', 'accepted_answers', 'outcome',
    ];

    protected function casts(): array
    {
        return ['numeric_answer' => 'array', 'matching' => 'array', 'accepted_answers' => 'array', 'mark' => 'decimal:2'];
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('position');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(QuestionVersion::class);
    }

    /** Everything needed to reproduce this question exactly as it was examined. */
    public function snapshot(): array
    {
        $this->loadMissing('options');

        return [
            'type' => $this->type,
            'stem_html' => $this->stem_html,
            'mark' => (float) $this->mark,
            'numeric_answer' => $this->numeric_answer,
            'matching' => $this->matching,
            'accepted_answers' => $this->accepted_answers,
            'explanation_html' => $this->explanation_html,
            'options' => $this->options->map(fn ($o) => ['id' => $o->id, 'label' => $o->label, 'html' => $o->body_html, 'correct' => $o->is_correct])->all(),
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(QuestionImport::class, 'question_import_id');
    }

    /** Matching data from pairs: left items keyed 1..n, right items a..z (shuffled when delivered). */
    public static function buildMatching(array $pairs, callable $clean): array
    {
        $left = $right = $answer = [];
        $rightKeys = [];
        foreach (array_values(array_filter($pairs, fn ($k) => $k !== '_distractors', ARRAY_FILTER_USE_KEY)) as $i => $p) {
            $lk = (string) ($i + 1);
            $text = trim((string) $p['right']);
            if (! isset($rightKeys[$text])) {
                $rightKeys[$text] = chr(97 + count($rightKeys));
                $right[] = ['key' => $rightKeys[$text], 'html' => $clean($text)];
            }
            $left[] = ['key' => $lk, 'html' => $clean(trim((string) $p['left']))];
            $answer[$lk] = $rightKeys[$text];
        }
        foreach ($pairs['_distractors'] ?? [] as $d) {
            $right[] = ['key' => chr(97 + count($right)), 'html' => $clean($d)];
        }

        return ['left' => $left, 'right' => $right, 'answer' => $answer];
    }
}
