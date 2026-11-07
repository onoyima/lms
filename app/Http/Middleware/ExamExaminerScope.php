<?php

namespace App\Http\Middleware;

use App\Models\ExamPanelAccount;
use App\Models\Meeting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Examiner accounts made for one panel exam can only:
 *  - open and save that exam's scoring sheet,
 *  - enter the meeting room linked to that exam (a viva or defence held online),
 *  - sign out.
 * Anything else sends them back to their scoring sheet. When the exam is over, the account was
 * removed, or the exams office reset its session (or it signed in elsewhere), it is signed out here.
 */
class ExamExaminerScope
{
    private const ALLOWED = ['marking.panel', 'marking.panel.store', 'meetings.show', 'meetings.join', 'meetings.status', 'logout'];

    public static function sessionKey(): string
    {
        return 'exam_examiner_token';
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $user->user_type !== ExamPanelAccount::USER_TYPE) {
            return $next($request);
        }
        $account = ExamPanelAccount::with('exam')->where('user_id', $user->id)->first();
        $mine = $request->hasSession() ? (string) $request->session()->get(self::sessionKey()) : '';

        $why = match (true) {
            ! $account => 'This examiner account no longer exists.',
            $account->isOver() => 'The exam this examiner account was made for is over, so the account can no longer be used.',
            $mine === '' || ! $account->session_token || ! hash_equals($account->session_token, hash('sha256', $mine)) => 'Your session was ended by the exams office or you signed in on another computer. Sign in again.',
            default => null,
        };
        if ($why) {
            if ($account && $mine !== '' && $account->session_token && hash_equals($account->session_token, hash('sha256', $mine))) {
                $account->forceFill(['session_token' => null])->save(); // exam over: free the lock as well
            }
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['message' => $why], 401)
                : redirect('/login')->with('status', $why);
        }

        if (! $account->last_seen_at || $account->last_seen_at->lt(now()->subMinute())) {
            $account->forceFill(['last_seen_at' => now(), 'last_ip' => $request->ip()])->save();
        }

        $route = $request->route();
        $name = $route?->getName();
        $examParam = $route?->parameter('exam');
        $examId = is_object($examParam) ? $examParam->getKey() : (int) $examParam;
        $meeting = $route?->parameter('meeting');
        $ok = match (true) {
            in_array($name, ['marking.panel', 'marking.panel.store'], true) => $examId === $account->exam_id,
            in_array($name, ['meetings.show', 'meetings.join', 'meetings.status'], true) => $meeting instanceof Meeting && (int) $meeting->setting('exam_id') === $account->exam_id,
            $name === 'logout' => true,
            default => false,
        };
        if ($ok) {
            return $next($request);
        }
        $home = '/marking/'.$account->exam_id.'/panel';

        return $request->isMethod('GET') && ! $request->expectsJson() ? redirect($home) : abort(403, 'This examiner account is only for '.$account->exam?->title.'.');
    }
}
