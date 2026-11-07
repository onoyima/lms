<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Platform-owned record (not scoped to an institution's data). */
class Payment extends Model
{
    protected $fillable = ['tenant_id', 'invoice_id', 'provider', 'reference', 'amount', 'currency', 'status', 'paid_at', 'recorded_by', 'note', 'meta'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'paid_at' => 'datetime', 'amount' => 'integer'];
    }
}
