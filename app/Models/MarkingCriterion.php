<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarkingCriterion extends Model
{
    protected $table = 'marking_criteria';

    protected $fillable = ['exam_id', 'text', 'allowed_scores', 'max_score', 'position'];

    protected function casts(): array
    {
        return [
            'allowed_scores' => 'array',
            'max_score' => 'float',
        ];
    }

    public function exam(): BelongsTo { return $this->belongsTo(Exam::class); }

    /** Legacy "0,0.5,1" strings and viva "any value up to max". */
    public function accepts(float $score): bool
    {
        if ($score < 0 || $score > (float) $this->max_score + 1e-9) {
            return false;
        }
        if (empty($this->allowed_scores)) {
            return true;
        }
        foreach ($this->allowed_scores as $allowed) {
            if (abs((float) $allowed - $score) < 1e-9) {
                return true;
            }
        }

        return false;
    }
}
