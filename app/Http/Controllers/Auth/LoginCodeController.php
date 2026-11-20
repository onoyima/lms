<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Sms\SmsSender;
use App\Support\Policy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sign in with a one-time code instead of a password: a 6-digit code goes to the person's email,
 * or by text message when the institution has SMS and the person has a mobile number. A code works
 * once, for 10 minutes, with 5 tries. Two-step sign-in still applies after it.
 */
class LoginCodeController extends Controller
{
    private const MINUTES = 10;

    public function show(Request $request): Response
    {
        abort_unless(Policy::get('otp_login', true), 404);

        return Inertia::render('auth/login-code', [
            'sent' => $request->session()->get('login_code.sent'),
            'sms' => SmsSender::enabled(),
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        abort_unless(Policy::get('otp_login', true), 404);
        $data = $request->validate(['login' => ['required', 'string', 'max:190'], 'via' => ['nullable', 'in:email,sms']]);
        $key = 'login-code:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['login' => 'Too many codes asked for. Try again in '.ceil(RateLimiter::availableIn($key) / 60).' minutes.']);
        }
        RateLimiter::hit($key, 900);

        $login = trim($data['login']);
        $user = User::forSignIn()->where(fn ($q) => $q->where('email', mb_strtolower($login))->orWhere('identifier', $login)->orWhere('identifier', mb_strtoupper($login)))
            ->where('status', 'active')->orderByRaw('users.tenant_id IS NULL')->first();
        $via = ($data['via'] ?? 'email') === 'sms' && $user?->phone && SmsSender::enabled() ? 'sms' : 'email';
        $sentTo = null;
        if ($user && ! in_array($user->user_type, ['exam_guest', \App\Models\ExamPanelAccount::USER_TYPE], true)) {
            $code = (string) random_int(100000, 999999);
            DB::table('login_codes')->where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]); // only the newest code works
            DB::table('login_codes')->insert(['tenant_id' => $user->tenant_id, 'user_id' => $user->id, 'code_hash' => hash('sha256', $code.'|'.config('app.key')),
                'channel' => $via, 'expires_at' => now()->addMinutes(self::MINUTES), 'ip' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);
            if ($via === 'sms') {
                app(SmsSender::class)->send($user->phone, "Your sign-in code is $code. It works for ".self::MINUTES.' minutes. Never share it.', 'login_code', $user->id);
                $sentTo = 'your mobile number ending '.substr(preg_replace('/\D/', '', (string) $user->phone), -3);
            } else {
                $user->notify(new \App\Notifications\LoginCode($code, self::MINUTES));
                $sentTo = 'your email '.preg_replace('/(?<=.).(?=[^@]*@)/', '•', (string) $user->email);
            }
        }
        // the same answer whether or not the account exists, so this cannot be used to find accounts
        $request->session()->put('login_code.user', $user?->id);
        $request->session()->put('login_code.sent', ['to' => $sentTo ?? 'your email or mobile number, if the account exists', 'login' => $login]);

        return redirect()->route('login-code.show');
    }

    public function verify(Request $request): RedirectResponse
    {
        abort_unless(Policy::get('otp_login', true), 404);
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $userId = $request->session()->get('login_code.user');
        $row = $userId ? DB::table('login_codes')->where('user_id', $userId)->whereNull('used_at')->where('expires_at', '>', now())->latest('id')->first() : null;
        if (! $row || $row->tries >= 5) {
            throw ValidationException::withMessages(['code' => 'That code is not valid any more. Ask for a new one.']);
        }
        if (! hash_equals($row->code_hash, hash('sha256', $data['code'].'|'.config('app.key')))) {
            DB::table('login_codes')->where('id', $row->id)->increment('tries');
            \App\Models\SecurityEvent::create(['tenant_id' => tenant_id(), 'user_id' => $userId, 'type' => 'login_failed', 'ip' => $request->ip(), 'path' => 'login/code', 'data' => ['method' => 'code'], 'created_at' => now()]);
            throw ValidationException::withMessages(['code' => 'That code is not right. Check it and try again.']);
        }
        DB::table('login_codes')->where('id', $row->id)->update(['used_at' => now()]);
        $user = User::forSignIn()->whereKey($userId)->where('status', 'active')->firstOrFail();
        $request->session()->forget(['login_code.user', 'login_code.sent']);

        // two-step sign-in, when switched on, still comes next
        if ($user->two_factor_secret && $user->two_factor_confirmed_at) {
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => false]);

            return redirect()->route('two-factor.login');
        }
        Auth::login($user);
        $request->session()->regenerate();

        return app(\App\Http\Responses\LoginResponse::class)->toResponse($request);
    }
}
