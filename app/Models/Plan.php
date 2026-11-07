<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = ['key', 'name', 'tagline', 'description', 'modules', 'sort', 'currency', 'price_monthly', 'price_quarterly', 'price_biannual', 'price_yearly', 'student_limit', 'limits',
        'trial_days', 'grace_days', 'addon_prices', 'features', 'is_public', 'highlighted'];

    protected function casts(): array
    {
        return ['modules' => 'array', 'features' => 'array', 'is_public' => 'boolean', 'highlighted' => 'boolean', 'price_monthly' => 'integer', 'price_yearly' => 'integer',
            'price_quarterly' => 'integer', 'price_biannual' => 'integer', 'limits' => 'array', 'addon_prices' => 'array'];
    }

    /** Price for one billing cycle (minor units). Quarterly and half-year default to 3 and 6 months. */
    public function priceFor(string $cycle): int
    {
        return match ($cycle) {
            'monthly' => (int) $this->price_monthly,
            'quarterly' => (int) ($this->price_quarterly ?? $this->price_monthly * 3),
            'biannual' => (int) ($this->price_biannual ?? $this->price_monthly * 6),
            default => (int) $this->price_yearly,
        };
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }
}
