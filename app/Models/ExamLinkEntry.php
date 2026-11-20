<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamLinkEntry extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'exam_link_id', 'user_id', 'exam_candidate_id', 'guest', 'name', 'email', 'phone', 'identifier', 'browser_hash', 'ip'];

    protected $hidden = ['browser_hash'];

    protected function casts(): array
    {
        return ['guest' => 'boolean'];
    }

    public function link(): BelongsTo { return $this->belongsTo(ExamLink::class, 'exam_link_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function candidate(): BelongsTo { return $this->belongsTo(ExamCandidate::class, 'exam_candidate_id'); }
}
