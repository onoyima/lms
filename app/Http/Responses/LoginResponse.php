<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;

/** After sign-in: platform administrators go to the platform console, everyone else to their institution. */
class LoginResponse implements LoginResponseContract, TwoFactorLoginResponseContract
{
    public function toResponse($request)
    {
        if ($request->user()?->isPlatformAdmin()) {
            return $request->wantsJson() ? response()->json(['two_factor' => false, 'redirect' => '/platform']) : redirect('/platform');
        }

        return $request->wantsJson() ? response()->json(['two_factor' => false]) : redirect()->intended(config('fortify.home'));
    }
}
