<?php

namespace App\Support\Exams;

use App\Http\Middleware\ExamExaminerScope;
use App\Models\Exam;
use App\Models\ExamPanelAccount;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Examiner accounts for one panel exam (viva, practical, research defence).
 * Created by the exams office with first name, last name, username and password; they work only
 * for that exam, only until it is over, and on one computer at a time (the office can reset that).
 */
class ExamExaminers
{
    public static function create(Exam $exam, array $data, User $by): ExamPanelAccount
    {
        return DB::transaction(function () use ($exam, $data, $by) {
            $username = mb_strtolower(trim($data['username']));
            $user = new User;
            $user->forceFill([
                'name' => trim($data['first_name']).' '.trim($data['last_name']),
                // a placeholder address on the reserved .invalid domain: these accounts never receive e-mail
                'email' => $username.'.'.Str::lower(Str::random(10)).'@exam-examiner.invalid',
                'password' => Hash::make($data['password']),
                'user_type' => ExamPanelAccount::USER_TYPE, 'status' => 'active', 'email_verified_at' => now(),
                'tenant_id' => $exam->tenant_id,
            ])->save();
            $user->givePermissionTo('results.mark'); // the scoring sheet's own check; the scope middleware limits it to this exam
            $exam->examiners()->syncWithoutDetaching([$user->id => ['role' => 'external']]);

            return ExamPanelAccount::create(['exam_id' => $exam->id, 'user_id' => $user->id, 'first_name' => trim($data['first_name']),
                'last_name' => trim($data['last_name']), 'username' => $username, 'created_by' => $by->id]);
        });
    }

    /** Before the password is accepted: the exam must still be on, and no other computer signed in. */
    public static function checkSignIn(User $user): void
    {
        $account = ExamPanelAccount::with('exam')->where('user_id', $user->id)->first();
        $fail = fn (string $m) => throw ValidationException::withMessages([\Laravel\Fortify\Fortify::username() => $m]);
        if (! $account) {
            $fail('This examiner account no longer exists.');
        }
        if ($account->isOver()) {
            $fail('The exam this examiner account was made for ('.$account->exam?->title.') is over. The account can no longer sign in.');
        }
        if ($account->isSignedIn()) {
            SecurityEvent::create(['tenant_id' => $account->tenant_id, 'user_id' => $user->id, 'type' => 'second_sign_in_refused', 'ip' => request()->ip(),
                'path' => 'login', 'data' => ['exam' => $account->exam_id], 'created_at' => now()]);
            $fail('This examiner account is already signed in on another computer. Ask the exams office to reset your session.');
        }
    }

    /** After sign-in: this browser holds the account's one session. */
    public static function claim(User $user, Request $request): void
    {
        if ($user->user_type !== ExamPanelAccount::USER_TYPE || ! $request->hasSession()) {
            return;
        }
        $token = Str::random(48);
        $request->session()->put(ExamExaminerScope::sessionKey(), $token);
        ExamPanelAccount::where('user_id', $user->id)->update(['session_token' => hash('sha256', $token), 'session_started_at' => now(),
            'last_seen_at' => now(), 'last_ip' => $request->ip()]);
    }

    /** Signing out frees the account for another computer (only the session that holds it can). */
    public static function release(User $user, Request $request): void
    {
        if ($user->user_type !== ExamPanelAccount::USER_TYPE || ! $request->hasSession()) {
            return;
        }
        $mine = (string) $request->session()->get(ExamExaminerScope::sessionKey());
        if ($mine !== '') {
            ExamPanelAccount::where('user_id', $user->id)->where('session_token', hash('sha256', $mine))->update(['session_token' => null, 'session_started_at' => null]);
        }
    }

    /** The exams office ends the account's session wherever it is, so the examiner can sign in again. */
    public static function resetSession(ExamPanelAccount $account): void
    {
        $account->forceFill(['session_token' => null, 'session_started_at' => null])->save();
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $account->user_id)->delete();
        }
    }
}
