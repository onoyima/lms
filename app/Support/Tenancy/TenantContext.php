<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;

/**
 * Holds the institution the current request (or job, or console command) belongs to.
 * Bound as a singleton; set by the ResolveTenant middleware or explicitly with run().
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
        // Spatie roles/permissions are "teams" keyed by tenant_id
        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($tenant?->id);
        }
        // e-mail goes out through the institution's own mail server when it has set one (LMS 220)
        \App\Services\Integrations\InstitutionMail::apply($tenant);
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function check(): bool
    {
        return $this->tenant !== null;
    }

    /**
     * Run a callback as a given institution, then restore the previous one.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(?Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;
        $this->set($tenant);

        try {
            return $callback();
        } finally {
            $this->set($previous);
        }
    }
}
