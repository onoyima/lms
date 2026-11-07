<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Laravel\Fortify\Contracts\FailedTwoFactorLoginResponse;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest as FortifyTwoFactorLoginRequest;

/**
 * The two-factor step finds the person the same way the password step did (this institution's people
 * and platform administrators), instead of through the institution-only scope. Without this, a platform
 * administrator signing in at an institution's address was sent back to the sign-in page after the password.
 */
class TwoFactorLoginRequest extends FortifyTwoFactorLoginRequest
{
    private ?User $found = null;

    private function lookup(): ?User
    {
        $id = $this->session()->get('login.id');

        return $this->found ??= $id ? User::forSignIn()->find($id) : null;
    }

    public function hasChallengedUser()
    {
        return $this->session()->has('login.id') && $this->lookup() !== null;
    }

    public function challengedUser()
    {
        $user = $this->lookup();
        if (! $user) {
            throw new HttpResponseException(app(FailedTwoFactorLoginResponse::class)->toResponse($this));
        }

        return $user;
    }
}
