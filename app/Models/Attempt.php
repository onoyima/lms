<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Attempt extends Model
{
    use BelongsToTenant;

    protected $fillable = ['exam_id', 'exam_session_id', 'exam_candidate_id', 'user_id', 'attempt_no', 'status', 'channel', 'started_at', 'deadline_at', 'submitted_at', 'submit_reason', 'paused_at', 'paused_seconds', 'token_hash', 'device_hash', 'ip', 'user_agent', 'last_seen_at', 'client_status', 'answered_count', 'violation_count', 'risk_score', 'score_raw', 'score_max', 'needs_marking', 'submission_hash'];

    protected $hidden = ['token_hash', 'device_hash'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'deadline_at' => 'datetime',
            'submitted_at' => 'datetime',
            'paused_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'needs_marking' => 'boolean',
            'score_raw' => 'float',
            'score_max' => 'float',
        ];
    }

    public const OPEN = ['in_progress', 'paused', 'locked'];
    public const CLOSED = ['submitted', 'auto_submitted', 'terminated'];

    public function isOpen(): bool { return in_array($this->status, self::OPEN, true); }

    /** Seconds left on the server clock (pauses stop the clock). */
    public function remainingSeconds(?\DateTimeInterface $now = null): int
    {
        if (! $this->isOpen()) {
            return 0;
        }
        $now = $now ? \Illuminate\Support\Carbon::instance($now) : now();
        $end = $this->paused_at ? $this->deadline_at->copy()->addSeconds((int) $this->paused_at->diffInSeconds($now)) : $this->deadline_at;

        return max(0, (int) floor($now->diffInSeconds($end, false)));
    }

    public function exam(): BelongsTo { return $this->belongsTo(Exam::class); }
    public function session(): BelongsTo { return $this->belongsTo(ExamSession::class, 'exam_session_id'); }
    public function candidate(): BelongsTo { return $this->belongsTo(ExamCandidate::class, 'exam_candidate_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function paper(): HasOne { return $this->hasOne(AttemptPaper::class); }
    public function questions(): HasMany { return $this->hasMany(AttemptQuestion::class)->orderBy('position'); }
    public function answers(): HasMany { return $this->hasMany(AttemptAnswer::class); }
    public function events(): HasMany { return $this->hasMany(ProctoringEvent::class); }
    public function messages(): HasMany { return $this->hasMany(CandidateMessage::class); }
    public function incidents(): HasMany { return $this->hasMany(Incident::class); }
}
