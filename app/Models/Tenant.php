<?php

namespace App\Models;

use App\Support\Branding;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $fillable = ['slug', 'name', 'short_name', 'plan_id', 'domains', 'brand_color', 'logo_path', 'status', 'settings', 'contact_name', 'contact_email', 'contact_phone', 'suspension_reason', 'approved_at'];

    protected function casts(): array
    {
        return ['domains' => 'array', 'settings' => 'array', 'approved_at' => 'datetime'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function moduleOverrides(): HasMany
    {
        return $this->hasMany(TenantModule::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Values the front end needs to theme itself for this institution. */
    public function brandPayload(): array
    {
        $b = (array) ($this->settings['branding'] ?? []);

        return [
            'name' => $this->name,
            'short_name' => $this->short_name ?: $this->name,
            'slug' => $this->slug,
            'brand' => $this->brand_color,
            'brand_foreground' => Branding::readableTextOn($this->brand_color),
            'logo_url' => $this->logo_path ? asset('storage/'.$this->logo_path) : null,
            'accent' => $b['secondary_color'] ?? null,
            'accent_foreground' => isset($b['secondary_color']) ? Branding::readableTextOn($b['secondary_color']) : null,
            'favicon_url' => ! empty($b['favicon_path']) ? asset('storage/'.$b['favicon_path']) : null,
            'login_title' => $b['login_title'] ?? null,
            'login_message' => $b['login_message'] ?? null,
            'welcome_message' => $b['welcome_message'] ?? null,
            'contact' => array_filter(['email' => $b['official_email'] ?? null, 'phone' => $b['phone'] ?? null, 'address' => $b['address'] ?? null]) ?: null,
        ];
    }

    /** Resolved on every request, so the host map and tenant rows are cached (cleared when a tenant changes). */
    public static function findByHost(string $host): ?self
    {
        $host = strtolower($host);
        $map = \Illuminate\Support\Facades\Cache::remember('tenants:host-map', 300, fn () => static::query()->get(['id', 'domains'])
            ->flatMap(fn (Tenant $t) => collect($t->domains ?? [])->mapWithKeys(fn ($d) => [strtolower($d) => $t->id]))->all());

        return isset($map[$host]) ? static::cached($map[$host]) : null;
    }

    public static function cached(int $id): ?self
    {
        // Plain attributes only: the cache never unserialises objects (Laravel 13 default, kept on purpose)
        $attributes = \Illuminate\Support\Facades\Cache::remember("tenants:$id", 300, fn () => static::find($id)?->getAttributes());

        return $attributes ? (new static)->newFromBuilder($attributes) : null;
    }

    protected static function booted(): void
    {
        $forget = function (Tenant $t) {
            \Illuminate\Support\Facades\Cache::forget('tenants:host-map');
            \Illuminate\Support\Facades\Cache::forget("tenants:{$t->id}");
        };
        static::saved($forget);
        static::deleted($forget);
    }
}
