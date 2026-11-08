<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * A browser that answered a two-factor challenge correctly, so the code is not
 * asked again on it for a while. The cookie holds a random token; only its hash
 * is stored, and it is tied to the account's current two-factor secret so that
 * turning two-factor off and on again does not carry the trust over.
 */
class TrustedDevice extends Model
{
    public const COOKIE = 'two_factor_trusted';

    protected $fillable = [
        'user_id',
        'token_hash',
        'secret_hash',
        'ip',
        'user_agent',
        'last_used_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function days(): int
    {
        return max(0, (int) config('platform.trust_two_factor_days', 30));
    }

    /**
     * Remember this browser and return the value to put in its cookie.
     */
    public static function issue(User $user, Request $request): string
    {
        static::query()
            ->where('user_id', $user->getKey())
            ->where('expires_at', '<', now())
            ->delete();

        $token = Str::random(64);

        $device = static::create([
            'user_id' => $user->getKey(),
            'token_hash' => hash('sha256', $token),
            'secret_hash' => static::fingerprint($user),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
            'last_used_at' => now(),
            'expires_at' => now()->addDays(static::days()),
        ]);

        return $device->getKey().'|'.$token;
    }

    /**
     * Find the stored device for a cookie value, if it still belongs to this
     * account, has not expired, and matches the account's current secret.
     */
    public static function resolve(?string $cookie, User $user): ?self
    {
        if (! $cookie || ! str_contains($cookie, '|')) {
            return null;
        }

        [$id, $token] = explode('|', $cookie, 2);

        if ($id === '' || $token === '') {
            return null;
        }

        $device = static::query()->find($id);

        if (! $device || (string) $device->user_id !== (string) $user->getKey()) {
            return null;
        }

        if (! hash_equals($device->token_hash, hash('sha256', $token))) {
            return null;
        }

        if (! $device->expires_at || $device->expires_at->isPast()) {
            return null;
        }

        if (! $user->two_factor_secret || ! $user->two_factor_confirmed_at) {
            return null;
        }

        if (! hash_equals($device->secret_hash, static::fingerprint($user))) {
            return null;
        }

        return $device;
    }

    public function markUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->save();
    }

    public static function forget(User $user): void
    {
        static::query()->where('user_id', $user->getKey())->delete();
    }

    public static function cookie(string $value, Request $request): SymfonyCookie
    {
        return Cookie::make(
            self::COOKIE,
            $value,
            static::days() * 24 * 60,
            '/',
            null,
            $request->isSecure() || app()->isProduction(),
            true,
            false,
            'lax',
        );
    }

    protected static function fingerprint(User $user): string
    {
        return hash('sha256', (string) $user->two_factor_secret);
    }
}
