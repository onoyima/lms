<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarkingCriterion extends Model
{
    protected $table = 'marking_criteria';

    protected $fillable = ['exam_id', 'station', 'text', 'kind', 'allowed_scores', 'max_score', 'expected', 'tolerance', 'unit', 'critical', 'guide', 'position'];

    /** How a line is marked: a score scale, done / not done, or a measured reading against the expected value. */
    public const KINDS = ['score', 'check', 'measure'];

    protected function casts(): array
    {
        return [
            'allowed_scores' => 'array',
            'max_score' => 'float',
            'expected' => 'float',
            'tolerance' => 'float',
            'critical' => 'boolean',
        ];
    }

    public function exam(): BelongsTo { return $this->belongsTo(Exam::class); }

    /**
     * The score for a measured reading: within the tolerance earns the full mark, within twice the tolerance
     * half of it, further out nothing.
     */
    public function scoreForReading(float $reading): float
    {
        $off = abs($reading - (float) $this->expected);
        $tol = max(0.0, (float) $this->tolerance);
        $max = (float) $this->max_score;

        return $off <= $tol + 1e-9 ? $max : ($off <= 2 * $tol + 1e-9 ? round($max / 2, 2) : 0.0);
    }

    /** Legacy "0,0.5,1" strings and viva "any value up to max". */
    public function accepts(float $score): bool
    {
        if ($this->kind === 'check') {
            return abs($score) < 1e-9 || abs($score - (float) $this->max_score) < 1e-9;
        }

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
