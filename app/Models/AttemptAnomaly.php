<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An attempt whose pattern is statistically unusual for its exam; for human review only (Target 171). */
class AttemptAnomaly extends Model
{
    use BelongsToTenant;

    protected $fillable = ['exam_id', 'attempt_id', 'score', 'reasons', 'review', 'reviewed_by'];

    protected function casts(): array
    {
        return ['reasons' => 'array', 'score' => 'float'];
    }

    public function attempt(): BelongsTo { return $this->belongsTo(Attempt::class); }
}
