<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CriterionMark extends Model
{
    use BelongsToTenant;

    protected $fillable = ['exam_id', 'marking_criterion_id', 'user_id', 'examiner_id', 'score', 'comment'];

    protected function casts(): array
    {
        return [
            'score' => 'float',
        ];
    }

    public function criterion(): BelongsTo { return $this->belongsTo(MarkingCriterion::class, 'marking_criterion_id'); }
    public function examiner(): BelongsTo { return $this->belongsTo(User::class, 'examiner_id'); }
}
