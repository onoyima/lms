<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Incident extends Model
{
    use BelongsToTenant;

    protected $fillable = ['exam_session_id', 'attempt_id', 'type', 'severity', 'status', 'source', 'needs_human_review', 'title', 'description', 'opened_by', 'assigned_to', 'reviewed_by', 'resolution', 'resolved_at'];

    protected function casts(): array
    {
        return [
            'needs_human_review' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    public function attempt(): BelongsTo { return $this->belongsTo(Attempt::class); }
    public function session(): BelongsTo { return $this->belongsTo(ExamSession::class, 'exam_session_id'); }
    public function notes(): HasMany { return $this->hasMany(IncidentNote::class)->latest('id'); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function evidence(): HasMany { return $this->hasMany(EvidenceFile::class); }
}
