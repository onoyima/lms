<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Extra capacity bought on top of the package (platform-owned record). */
class TenantAddon extends Model
{
    protected $fillable = ['tenant_id', 'limit_key', 'quantity', 'status', 'starts_at', 'ends_at', 'invoice_id', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function tenant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeCurrent($q)
    {
        return $q->where('status', 'active')->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }
}
