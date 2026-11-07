<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantModule extends Model
{
    protected $fillable = ['tenant_id', 'module', 'enabled', 'expires_at', 'reason', 'granted_by'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'expires_at' => 'datetime'];
    }

    /** A temporary grant that has run out no longer counts. */
    public function current(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
