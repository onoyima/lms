<?php

namespace App\Services\Settings;

use App\Models\SettingsVersion;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Every saved version of the institution's policy (and each campus's own settings) is kept, so a
 * change can be compared and rolled back (Target 170). Rolling back saves a new version; nothing is erased.
 */
class SettingsHistory
{
    public function __construct(private TenantContext $context, private AuditLogger $audit) {}

    /** Where each scope lives inside tenants.settings */
    public static function path(string $scope): string
    {
        return $scope === 'policy' ? 'policy' : 'campus_policy.'.substr($scope, 7);
    }

    public function current(string $scope): array
    {
        return (array) data_get($this->context->get()->settings, self::path($scope), []);
    }

    /** Save new settings for a scope and keep the version. */
    public function save(string $scope, array $data, ?string $note = null, ?int $restoredFrom = null): SettingsVersion
    {
        abort_unless($scope === 'policy' || preg_match('/^campus:[a-z0-9_]{1,40}$/', $scope), 422);

        return DB::transaction(function () use ($scope, $data, $note, $restoredFrom) {
            $tenant = $this->context->get();
            $before = $this->current($scope);
            // keep the state before history began, so the first change can be undone too
            if (! SettingsVersion::where('scope', $scope)->exists() && $before !== []) {
                SettingsVersion::create(['scope' => $scope, 'data' => $before, 'note' => 'Settings before history was kept']);
            }
            $settings = (array) $tenant->settings;
            data_set($settings, self::path($scope), $data);
            $tenant->settings = $settings;
            $tenant->save();
            $v = SettingsVersion::create(['scope' => $scope, 'data' => $data, 'user_id' => auth()->id(), 'note' => $note, 'restored_from' => $restoredFrom]);
            $this->audit->record($restoredFrom ? 'settings.restored' : 'settings.updated', $tenant, ['scope' => $scope, 'version' => $v->id, 'before' => $before, 'after' => $data]);

            return $v;
        });
    }

    public function restore(SettingsVersion $version): SettingsVersion
    {
        return $this->save($version->scope, $version->data, 'Rolled back to version '.$version->id, $version->id);
    }

    /** Keys whose value differs between two versions. */
    public static function diff(array $a, array $b): array
    {
        $keys = array_unique(array_merge(array_keys($a), array_keys($b)));

        return array_values(array_filter($keys, fn ($k) => json_encode($a[$k] ?? null) !== json_encode($b[$k] ?? null)));
    }
}
