<?php

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;

if (! function_exists('tenant')) {
    function tenant(): ?Tenant
    {
        return app(TenantContext::class)->get();
    }
}

if (! function_exists('tenant_id')) {
    function tenant_id(): ?int
    {
        return app(TenantContext::class)->id();
    }
}
