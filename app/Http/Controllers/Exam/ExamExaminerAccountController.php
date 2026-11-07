<?php

namespace App\Http\Controllers\Exam;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamPanelAccount;
use App\Services\Audit\AuditLogger;
use App\Support\Exams\ExamExaminers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** The exams office makes, resets and removes examiner accounts that exist only for one panel exam. */
class ExamExaminerAccountController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function store(Request $request, Exam $exam): RedirectResponse
    {
        abort_unless($exam->isPanelMarked(), 422, 'Exam-only examiner accounts are for viva, practical and research (defence) exams.');
        $request->merge(['username' => mb_strtolower(trim((string) $request->input('username')))]);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'username' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('exam_panel_accounts', 'username')->where('tenant_id', tenant_id()),
                function ($attr, $value, $fail) {
                    if (\App\Models\User::where('identifier', $value)->orWhere('email', mb_strtolower($value))->exists()) {
                        $fail('That username is already someone’s matric or staff number. Choose another.');
                    }
                }],
            'password' => ['required', 'string', Password::min(8)],
        ], ['username.regex' => 'Use letters, numbers, dots, dashes or underscores only (no spaces).']);
        $account = ExamExaminers::create($exam, $data, $request->user());
        $this->audit->record('exam.examiner_account_created', $exam, ['username' => $account->username, 'name' => $account->first_name.' '.$account->last_name]);

        return back()->with('success', "Examiner account {$account->username} created. It works only for this exam, until the exam is over.");
    }

    public function resetSession(Request $request, Exam $exam, ExamPanelAccount $account): RedirectResponse
    {
        abort_unless($account->exam_id === $exam->id, 404);
        ExamExaminers::resetSession($account);
        $this->audit->record('exam.examiner_session_reset', $exam, ['username' => $account->username]);

        return back()->with('success', "{$account->first_name} {$account->last_name} was signed out and can sign in again on any one computer.");
    }

    public function password(Request $request, Exam $exam, ExamPanelAccount $account): RedirectResponse
    {
        abort_unless($account->exam_id === $exam->id, 404);
        $data = $request->validate(['password' => ['required', 'string', Password::min(8)]]);
        $account->user->forceFill(['password' => Hash::make($data['password'])])->save();
        ExamExaminers::resetSession($account);
        $this->audit->record('exam.examiner_password_set', $exam, ['username' => $account->username]);

        return back()->with('success', "New password set for {$account->username}.");
    }

    public function destroy(Request $request, Exam $exam, ExamPanelAccount $account): RedirectResponse
    {
        abort_unless($account->exam_id === $exam->id, 404);
        ExamExaminers::resetSession($account);
        $account->forceFill(['revoked_at' => now()])->save();
        $account->user->forceFill(['status' => 'suspended'])->save(); // kept for the audit trail and their scores
        $this->audit->record('exam.examiner_account_removed', $exam, ['username' => $account->username]);

        return back()->with('success', "{$account->username} can no longer sign in. Scores already given stay.");
    }
}
