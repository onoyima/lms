<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Platform-owned record (not scoped to an institution's data). */
class Invoice extends Model
{
    protected $fillable = ['tenant_id', 'subscription_id', 'number', 'status', 'kind', 'amount', 'discount', 'currency', 'lines', 'meta', 'period_start', 'period_end', 'due_at', 'reminded_at', 'paid_at'];

    protected function casts(): array
    {
        return ['lines' => 'array', 'period_start' => 'datetime', 'period_end' => 'datetime', 'due_at' => 'datetime', 'paid_at' => 'datetime', 'reminded_at' => 'datetime', 'amount' => 'integer', 'discount' => 'integer', 'meta' => 'array'];
    }

    public function tenant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
