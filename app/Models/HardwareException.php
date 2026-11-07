<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A candidate allowed to sit without some proctoring hardware, approved by a second person (Target 141). */
class HardwareException extends Model
{
    use BelongsToTenant;

    public const KINDS = ['no_webcam', 'no_microphone', 'own_device', 'assistive_device', 'external_display', 'second_camera_exempt', 'other'];

    protected $fillable = ['user_id', 'exam_id', 'kind', 'detail', 'relax', 'status', 'requested_by', 'decided_by', 'decided_at', 'valid_until'];

    protected function casts(): array
    {
        return ['relax' => 'array', 'decided_at' => 'datetime', 'valid_until' => 'date'];
    }

    /** @return Collection<int, self> */
    public static function activeFor(int $userId, int $examId): Collection
    {
        return static::where('user_id', $userId)->where('status', 'approved')
            ->where(fn ($q) => $q->whereNull('exam_id')->orWhere('exam_id', $examId))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()->toDateString()))
            ->get();
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function exam(): BelongsTo { return $this->belongsTo(Exam::class); }
}
