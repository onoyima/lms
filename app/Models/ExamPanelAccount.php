<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An examiner account that exists only for one panel exam (see migration 039). */
class ExamPanelAccount extends Model
{
    use BelongsToTenant;

    public const USER_TYPE = 'exam_examiner';

    protected $fillable = ['exam_id', 'user_id', 'first_name', 'last_name', 'username', 'session_token', 'session_started_at', 'last_seen_at', 'last_ip', 'revoked_at', 'created_by'];

    protected $hidden = ['session_token'];

    protected function casts(): array
    {
        return ['session_started_at' => 'datetime', 'last_seen_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** When the exam is over: closed or archived, or its last sitting has ended. Null = not over yet. */
    public function endsAt(): ?\Carbon\CarbonInterface
    {
        $exam = $this->exam;
        if (! $exam || in_array($exam->status, ['closed', 'archived'], true)) {
            return $this->updated_at ?? now();
        }
        $last = $exam->sessions()->max('ends_at');

        return $last ? \Carbon\Carbon::parse($last) : null;
    }

    public function isOver(): bool
    {
        if ($this->revoked_at) {
            return true;
        }
        $exam = $this->exam;
        if (! $exam || in_array($exam->status, ['closed', 'archived'], true)) {
            return true;
        }
        $end = $this->endsAt();

        return $end !== null && $end->isPast();
    }

    /** Signed in somewhere right now (the lock lapses when that session would have timed out). */
    public function isSignedIn(): bool
    {
        return $this->session_token !== null && $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subMinutes((int) config('session.lifetime', 120)));
    }
}
