<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopOrder extends Model
{
    use BelongsToTenant;

    protected $fillable = ['user_id', 'number', 'subtotal', 'discount', 'total', 'currency', 'status', 'provider', 'reference', 'voucher_id', 'receipt_code', 'paid_at'];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShopOrderItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
