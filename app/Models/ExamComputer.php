<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A computer (by IP address) that asked to sit an exam, and the exams office's decision. */
class ExamComputer extends Model
{
    use BelongsToTenant;

    protected $fillable = ['ip', 'label', 'status', 'venue_id', 'last_user_id', 'user_agent', 'requests', 'first_seen_at', 'last_seen_at',
        'decided_by', 'decided_at', 'cleared_at', 'cleared_by', 'clear_reason'];

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'decided_at' => 'datetime', 'cleared_at' => 'datetime'];
    }

    /** The list in force now (not yet wiped). */
    public function scopeCurrent(Builder $q): Builder
    {
        return $q->whereNull('cleared_at');
    }

    public function lastUser(): BelongsTo { return $this->belongsTo(User::class, 'last_user_id'); }
    public function venue(): BelongsTo { return $this->belongsTo(Venue::class); }
}
