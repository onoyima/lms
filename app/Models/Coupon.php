<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A discount code platform administrators create (promotions, partner deals). */
class Coupon extends Model
{
    protected $fillable = ['code', 'description', 'kind', 'value', 'currency', 'plan_ids', 'cycles', 'max_redemptions', 'per_tenant', 'starts_at', 'ends_at', 'active'];

    protected function casts(): array
    {
        return ['plan_ids' => 'array', 'cycles' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'active' => 'boolean', 'value' => 'integer',
            'max_redemptions' => 'integer', 'per_tenant' => 'integer', 'redeemed' => 'integer'];
    }

    /** Why this code cannot be used here, or null when it can. */
    public function problem(Tenant $tenant, ?Plan $plan, string $cycle): ?string
    {
        return match (true) {
            ! $this->active => 'This code is no longer active.',
            $this->starts_at && $this->starts_at->isFuture() => 'This code is not valid yet.',
            $this->ends_at && $this->ends_at->isPast() => 'This code has expired.',
            $this->max_redemptions !== null && $this->redeemed >= $this->max_redemptions => 'This code has been used up.',
            $this->plan_ids && (! $plan || ! in_array($plan->id, $this->plan_ids, true)) => 'This code is not valid for this package.',
            $this->cycles && ! in_array($cycle, $this->cycles, true) => 'This code is not valid for this billing period.',
            \Illuminate\Support\Facades\DB::table('coupon_redemptions')->where('coupon_id', $this->id)->where('tenant_id', $tenant->id)->count() >= $this->per_tenant => 'Your institution has already used this code.',
            default => null,
        };
    }

    public function discountOn(int $amount): int
    {
        return $this->kind === 'percent' ? (int) round($amount * min(100, $this->value) / 100) : min($amount, $this->value);
    }
}
