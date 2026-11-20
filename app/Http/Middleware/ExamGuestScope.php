<?php

namespace App\Http\Middleware;

use App\Models\Attempt;
use App\Models\ExamLink;
use App\Models\ExamLinkEntry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guest accounts made by an exam link ("anyone with the link") can only open their own exam room,
 * start or resume that exam, and sign out. Once the sitting is over (and they are not still writing)
 * the account stops working.
 */
class ExamGuestScope
{
    private const ALLOWED = ['candidate.room', 'candidate.start', 'candidate.seb', 'exam-link.show', 'exam-link.enter', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $user->user_type !== ExamLink::GUEST_TYPE) {
            return $next($request);
        }
        $entry = ExamLinkEntry::with('link.session', 'candidate')->where('user_id', $user->id)->first();
        $session = $entry?->link?->session;
        $writing = $entry && Attempt::where('exam_candidate_id', $entry->exam_candidate_id)->whereIn('status', Attempt::OPEN)->exists();
        $over = ! $session || ($session->ends_at->copy()->addHour()->isPast() && ! $writing);
        if (! $entry || $over) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['message' => 'This exam is over.'], 401)
                : redirect('/')->with('status', 'This exam is over. Thank you.');
        }

        $route = $request->route();
        $name = $route?->getName();
        $candidate = $route?->parameter('candidate');
        $candidateId = is_object($candidate) ? $candidate->getKey() : (int) $candidate;
        $ok = match (true) {
            in_array($name, ['candidate.room', 'candidate.start', 'candidate.seb'], true) => $candidateId === (int) $entry->exam_candidate_id,
            in_array($name, self::ALLOWED, true) => true,
            default => false,
        };
        if ($ok) {
            return $next($request);
        }

        return $request->isMethod('GET') && ! $request->expectsJson()
            ? redirect()->route('candidate.room', $entry->exam_candidate_id)
            : abort(403, 'This account is only for '.$entry->link->session->exam?->title.'.');
    }
}
