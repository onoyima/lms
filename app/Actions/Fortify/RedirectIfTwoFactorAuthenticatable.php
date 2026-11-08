<?php

namespace App\Actions\Fortify;

use App\Models\TrustedDevice;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable as BaseRedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * Same as Fortify's action, except a browser that was trusted after an earlier
 * correct code is sent straight on instead of being asked for another code.
 */
class RedirectIfTwoFactorAuthenticatable extends BaseRedirectIfTwoFactorAuthenticatable
{
    public function handle($request, $next)
    {
        $user = $this->validateCredentials($request);

        if ($user && $this->isTrusted($request, $user)) {
            $request->session()->forget('login.id');

            return $next($request);
        }

        if (Fortify::confirmsTwoFactorAuthentication()) {
            if (optional($user)->two_factor_secret &&
                ! is_null(optional($user)->two_factor_confirmed_at) &&
                in_array(TwoFactorAuthenticatable::class, class_uses_recursive($user))) {
                return $this->twoFactorChallengeResponse($request, $user);
            }

            return $next($request);
        }

        if (optional($user)->two_factor_secret &&
            in_array(TwoFactorAuthenticatable::class, class_uses_recursive($user))) {
            return $this->twoFactorChallengeResponse($request, $user);
        }

        return $next($request);
    }

    protected function isTrusted($request, $user): bool
    {
        if (TrustedDevice::days() === 0) {
            return false;
        }

        $device = TrustedDevice::resolve($request->cookie(TrustedDevice::COOKIE), $user);

        if (! $device) {
            return false;
        }

        $device->markUsed();

        return true;
    }
}
