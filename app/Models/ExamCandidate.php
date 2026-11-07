<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamCandidate extends Model
{
    use BelongsToTenant;

    protected $fillable = ['exam_session_id', 'user_id', 'seat_id', 'eligibility', 'eligibility_reason', 'extra_time_minutes', 'accommodations', 'pin_hash', 'attendance', 'checked_in_at', 'checked_in_by', 'checked_out_at', 'consent_given'];

    protected $hidden = ['pin_hash'];

    protected function casts(): array
    {
        return [
            'accommodations' => 'array',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'consent_given' => 'boolean',
        ];
    }

    public function session(): BelongsTo { return $this->belongsTo(ExamSession::class, 'exam_session_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function seat(): BelongsTo { return $this->belongsTo(Seat::class); }
    public function attempts(): HasMany { return $this->hasMany(Attempt::class); }
}
