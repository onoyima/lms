<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only. Written only through App\Services\Audit\AuditLogger.
 * Not tenant-scoped automatically so platform-level events can be stored too;
 * always filter by tenant_id when showing it to an institution.
 */
class AuditLog extends Model
{
    public $timestamps = false; // created_at is set by AuditLogger so it is part of the hash

    protected $fillable = ['tenant_id', 'actor_id', 'action', 'subject_type', 'subject_id', 'data', 'ip', 'prev_hash', 'hash', 'created_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime:Y-m-d H:i:s.u'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }
}
