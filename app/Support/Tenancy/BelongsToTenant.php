<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Makes a model institution-owned:
 *  - every query is limited to the current institution (global scope);
 *  - new rows get the current institution's tenant_id automatically.
 * Use Model::withoutGlobalScope(TenantScope::class) only in platform-admin code.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            if (empty($model->tenant_id)) {
                $model->tenant_id = app(TenantContext::class)->id();
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeForTenant(Builder $query, Tenant|int $tenant): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class)
            ->where($this->qualifyColumn('tenant_id'), $tenant instanceof Tenant ? $tenant->id : $tenant);
    }
}
