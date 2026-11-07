<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProctorAssignment extends Model
{
    protected $fillable = ['exam_session_id', 'user_id', 'role', 'max_candidates', 'last_seen_at', 'offline_alerted_at'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'offline_alerted_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo { return $this->belongsTo(ExamSession::class, 'exam_session_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function candidates(): BelongsToMany { return $this->belongsToMany(ExamCandidate::class, 'proctor_candidate'); }
}
