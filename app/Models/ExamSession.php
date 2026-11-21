<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamSession extends Model
{
    /** A sitting added to an exam in a category takes the category's students straight away. */
    protected static function booted(): void
    {
        static::created(function (ExamSession $session) {
            $groupId = Exam::whereKey($session->exam_id)->value('exam_group_id');
            if (! $groupId) {
                return;
            }
            \Illuminate\Support\Facades\DB::afterCommit(function () use ($session, $groupId) {
                $ids = \Illuminate\Support\Facades\DB::table('exam_group_students')->where('exam_group_id', $groupId)->pluck('user_id')->all();
                if ($ids) {
                    app(\App\Services\Exams\CandidateRoster::class)->add($session, ['user_ids' => $ids]);
                }
            });
        });
    }

    use BelongsToTenant;

    protected $fillable = ['exam_id', 'venue_id', 'name', 'mode', 'starts_at', 'ends_at', 'late_entry_minutes', 'grace_seconds', 'access_pin_hash', 'status', 'paused_at', 'is_practice', 'exam_centre_id', 'centre_ref'];

    protected $hidden = ['access_pin_hash'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'paused_at' => 'datetime',
            'is_practice' => 'boolean',
        ];
    }

    public function exam(): BelongsTo { return $this->belongsTo(Exam::class); }
    public function venue(): BelongsTo { return $this->belongsTo(Venue::class); }
    public function candidates(): HasMany { return $this->hasMany(ExamCandidate::class); }
    public function attempts(): HasMany { return $this->hasMany(Attempt::class); }
    public function proctors(): HasMany { return $this->hasMany(ProctorAssignment::class); }
    public function incidents(): HasMany { return $this->hasMany(Incident::class); }
}
