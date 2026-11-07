<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Exam extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['course_id', 'semester_id', 'created_by', 'title', 'code', 'type', 'category', 'kind', 'parent_exam_id', 'instructions', 'status', 'total_mark', 'pass_mark', 'duration_minutes', 'max_attempts', 'score_policy', 'settings', 'proctoring', 'channels', 'marking_policy', 'version', 'approved_at', 'approved_by', 'legacy_ref'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'proctoring' => 'array',
            'channels' => 'array',
            'approved_at' => 'datetime',
            'total_mark' => 'decimal:2',
            'pass_mark' => 'decimal:2',
        ];
    }

    public const TYPES = ['cbt', 'essay', 'mixed', 'practical', 'viva', 'research'];

    /** Formats marked by examiners against criteria rather than by answering questions on screen. */
    public function isPanelMarked(): bool
    {
        return in_array($this->type, ['practical', 'viva', 'research'], true);
    }

    public function isOnScreen(): bool
    {
        return in_array($this->type, ['cbt', 'essay', 'mixed'], true);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    public function course(): BelongsTo { return $this->belongsTo(Course::class); }
    public function semester(): BelongsTo { return $this->belongsTo(Semester::class); }
    public function sections(): HasMany { return $this->hasMany(ExamSection::class)->orderBy('position'); }
    public function criteria(): HasMany { return $this->hasMany(MarkingCriterion::class)->orderBy('position'); }
    public function sessions(): HasMany { return $this->hasMany(ExamSession::class); }
    public function attempts(): HasMany { return $this->hasMany(Attempt::class); }
    public function results(): HasMany { return $this->hasMany(Result::class); }
    public function versions(): HasMany { return $this->hasMany(ExamVersion::class); }
    public function examiners(): BelongsToMany { return $this->belongsToMany(User::class, 'exam_examiners')->withPivot('role')->withTimestamps(); }
    public function approvals(): MorphMany { return $this->morphMany(Approval::class, 'approvable'); }
}
