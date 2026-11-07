<?php

namespace App\Services\Modules;

use App\Models\Tenant;
use InvalidArgumentException;

/**
 * Decides which modules an institution may use:
 *   plan modules + add-ons switched on − modules switched off, + core,
 *   then anything whose required module is missing is dropped.
 */
class ModuleRegistry
{
    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return config('modules');
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /** @return list<string> */
    public function enabledFor(?Tenant $tenant): array
    {
        $all = $this->all();
        $always = array_keys(array_filter($all, fn ($m) => $m['always_on'] ?? false));

        if (! $tenant) {
            return $always;
        }

        $enabled = array_fill_keys($tenant->plan?->modules ?? [], true);
        foreach ($tenant->moduleOverrides as $override) {
            if ($override->current()) { // temporary access ends on its date
                $enabled[$override->module] = $override->enabled;
            }
        }
        foreach ($always as $key) {
            $enabled[$key] = true;
        }

        $enabled = array_keys(array_filter($enabled));
        $enabled = array_values(array_filter($enabled, fn ($k) => isset($all[$k])));

        // Drop modules whose requirements are not met (repeat until stable).
        do {
            $before = count($enabled);
            $enabled = array_values(array_filter($enabled, function ($k) use ($all, $enabled) {
                foreach ($all[$k]['requires'] ?? [] as $req) {
                    if (! in_array($req, $enabled, true)) {
                        return false;
                    }
                }

                return true;
            }));
        } while (count($enabled) !== $before);

        return $enabled;
    }

    public function isEnabled(?Tenant $tenant, string $key): bool
    {
        if (! $this->exists($key)) {
            throw new InvalidArgumentException("Unknown module [$key].");
        }

        return in_array($key, $this->enabledFor($tenant), true);
    }

    /** Modules that would stop working if $key were switched off. @return list<string> */
    public function dependents(string $key): array
    {
        return array_keys(array_filter($this->all(), fn ($m) => in_array($key, $m['requires'] ?? [], true)));
    }
}
