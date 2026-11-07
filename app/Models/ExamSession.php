<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamSession extends Model
{
    use BelongsToTenant;

    protected $fillable = ['exam_id', 'venue_id', 'name', 'mode', 'starts_at', 'ends_at', 'late_entry_minutes', 'grace_seconds', 'access_pin_hash', 'status', 'paused_at', 'is_practice'];

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
