<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A discount code for the shop: a percentage, an amount, or free (for one package or any). */
class Voucher extends Model
{
    use BelongsToTenant;

    protected $fillable = ['code', 'kind', 'value', 'test_package_id', 'max_uses', 'used', 'expires_at', 'active'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'active' => 'boolean'];
    }

    public function usable(): bool
    {
        return $this->active && (! $this->expires_at || $this->expires_at->isFuture()) && ($this->max_uses === null || $this->used < $this->max_uses);
    }
}
