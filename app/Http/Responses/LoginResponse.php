<?php

namespace App\Http\Responses;

use App\Models\TrustedDevice;
use Illuminate\Support\Facades\Cookie;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;

/** After sign-in: platform administrators go to the platform console, everyone else to their institution. */
class LoginResponse implements LoginResponseContract, TwoFactorLoginResponseContract
{
    public function toResponse($request)
    {
        $this->trustDevice($request);

        if ($request->user()?->isPlatformAdmin()) {
            return $request->wantsJson() ? response()->json(['two_factor' => false, 'redirect' => '/platform']) : redirect('/platform');
        }

        return $request->wantsJson() ? response()->json(['two_factor' => false]) : redirect()->intended(config('fortify.home'));
    }

    /** After a correct two-factor code, remember this browser if the person asked for it. */
    private function trustDevice($request): void
    {
        if (! $request->routeIs('two-factor.login.store') || ! $request->user() || ! $request->boolean('trust_device')) {
            return;
        }

        if (TrustedDevice::days() === 0 || ! $request->user()->hasEnabledTwoFactorAuthentication()) {
            return;
        }

        Cookie::queue(TrustedDevice::cookie(TrustedDevice::issue($request->user(), $request), $request));
    }
}
