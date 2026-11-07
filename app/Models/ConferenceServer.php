<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A BigBlueButton server: shared by the platform (tenant_id NULL) or an institution's own. Its secret is encrypted and never leaves the server. */
class ConferenceServer extends Model
{
    protected $fillable = ['tenant_id', 'name', 'base_url', 'secret', 'checksum_algo', 'enabled', 'weight', 'max_participants', 'status', 'version', 'live_meetings', 'live_participants', 'last_error', 'checked_at'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'enabled' => 'boolean', 'checked_at' => 'datetime', 'weight' => 'integer', 'max_participants' => 'integer'];
    }

    /** Servers an institution may use: its own first, then the platform's shared ones. */
    public static function usableBy(?int $tenantId)
    {
        return static::query()->where('enabled', true)->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->orderByRaw('CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END');
    }
}
