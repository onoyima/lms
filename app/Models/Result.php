<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Result extends Model
{
    use BelongsToTenant;

    protected $fillable = ['exam_id', 'user_id', 'attempt_id', 'raw_score', 'max_score', 'final_score', 'grade', 'status', 'withheld_reason', 'locked', 'published_at', 'version'];

    protected function casts(): array
    {
        return [
            'locked' => 'boolean',
            'published_at' => 'datetime',
            'raw_score' => 'float',
            'max_score' => 'float',
            'final_score' => 'float',
        ];
    }

    public function exam(): BelongsTo { return $this->belongsTo(Exam::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function changes(): HasMany { return $this->hasMany(ResultChange::class)->latest('id'); }
    public function approvals(): MorphMany { return $this->morphMany(Approval::class, 'approvable'); }
}
