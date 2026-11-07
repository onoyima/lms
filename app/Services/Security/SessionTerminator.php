<?php

namespace App\Services\Security;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Signs a person out everywhere when they lose institution-wide power (a role removed, a role's privileged
 * permission removed) or their account is switched off. Sessions are deleted, "remember me" stops working,
 * and any session that survives (e.g. one mid-request) is refused by EnsureSessionCurrent on its next request.
 */
class SessionTerminator
{
    public static function cutoffKey(int $userId): string
    {
        return "sessions-after:$userId";
    }

    /** @return list<string> privileged permissions this person holds now */
    public static function privileged(User $user): array
    {
        if (! $user->exists || ! $user->tenant_id) {
            return [];
        }
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return $user->getAllPermissions()->pluck('name')->intersect(config('roles.privileged'))->values()->all();
    }

    public function endFor(User $user, string $reason): void
    {
        Cache::forever(self::cutoffKey($user->id), microtime(true));
        try {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        } catch (\Throwable) {
            // sessions kept elsewhere (redis, files): EnsureSessionCurrent refuses them on their next request
        }
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
        app(AuditLogger::class)->record('security.sessions_ended', $user, ['reason' => $reason]);
    }

    /** Compare before/after and sign out if any privileged permission was lost. */
    public function afterChange(User $user, array $before, string $reason): void
    {
        $lost = array_diff($before, self::privileged($user));
        if ($lost) {
            $this->endFor($user, $reason.': '.implode(', ', $lost));
        }
    }
}
