<?php

namespace App\Http\Controllers\Exam;

use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Models\ExamCandidate;
use App\Models\ExamLink;
use App\Models\ExamLinkEntry;
use App\Models\User;
use App\Services\Exams\CandidateRoster;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The link a candidate opens: /x/{token}. Depending on the sitting, students sign in with their
 * own account, or anyone types their name (and number or email) and gets an exam-only guest
 * account that works for this one exam. An access code, if set, is asked first.
 */
class ExamLinkController extends Controller
{
    private function link(string $token): ExamLink
    {
        return ExamLink::with('session.exam')->where('token', $token)->firstOrFail();
    }

    private function cookieName(ExamLink $link): string
    {
        return 'exam_guest_'.$link->id;
    }

    private function state(ExamLink $link): string
    {
        $s = $link->session;

        return match (true) {
            ! $link->enabled => 'off',
            ! in_array($s->exam->status, ['scheduled', 'live'], true) && ! $s->is_practice => 'not_approved',
            $s->status === 'closed' || $s->ends_at->isPast() => 'closed',
            $s->starts_at->isFuture() => 'not_yet',
            default => 'open',
        };
    }

    public function show(Request $request, string $token): Response
    {
        $link = $this->link($token);
        $user = $request->user();
        $mine = $user ? ExamCandidate::where('exam_session_id', $link->exam_session_id)->where('user_id', $user->id)->value('id') : null;

        return Inertia::render('exam-link', [
            'token' => $token,
            'exam' => ['title' => $link->session->exam->title, 'duration' => $link->session->exam->duration_minutes, 'instructions' => $link->session->exam->instructions],
            'sitting' => ['starts_at' => $link->session->starts_at->toIso8601String(), 'ends_at' => $link->session->ends_at->toIso8601String()],
            'state' => $this->state($link),
            'audience' => $link->audience,
            'needsCode' => (bool) $link->code_hash,
            'ask' => array_values(array_intersect((array) $link->ask, ['identifier', 'email', 'phone'])),
            'signedIn' => $user ? ['name' => $user->name, 'guest' => $user->user_type === ExamLink::GUEST_TYPE] : null,
            'candidateId' => $mine,
            'institution' => tenant()?->name,
        ]);
    }

    public function enter(Request $request, string $token, CandidateRoster $roster): RedirectResponse
    {
        $link = $this->link($token);
        $state = $this->state($link);
        if ($state !== 'open') {
            return back()->with('error', match ($state) {
                'off' => 'This exam link has been switched off.',
                'not_approved' => 'This exam is waiting for approval. Try again later.',
                'closed' => 'This exam has closed.',
                default => 'This exam opens at '.$link->session->starts_at->format('H:i, D j M').'.',
            });
        }
        if ($link->code_hash && ! $request->session()->get('exam_link_code.'.$link->id)) {
            $code = (string) $request->input('code');
            if ($code === '' || ! Hash::check($code, $link->code_hash)) {
                throw ValidationException::withMessages(['code' => 'That access code is not right.']);
            }
            $request->session()->put('exam_link_code.'.$link->id, true);
        }

        return $link->audience === 'anyone' ? $this->enterAsGuest($request, $link) : $this->enterAsMember($request, $link, $roster);
    }

    /** Students sign in with their own account; "roster" sittings only let in people on the list. */
    private function enterAsMember(Request $request, ExamLink $link, CandidateRoster $roster): RedirectResponse
    {
        $user = $request->user();
        if (! $user || $user->user_type === ExamLink::GUEST_TYPE) {
            $request->session()->put('url.intended', route('exam-link.show', $link->token));

            return redirect()->route('login')->with('status', 'Sign in to take '.$link->session->exam->title.'.');
        }
        $candidate = ExamCandidate::where('exam_session_id', $link->exam_session_id)->where('user_id', $user->id)->first();
        if (! $candidate) {
            abort_if($link->audience === 'roster', 403, 'You are not on the list for this exam. Speak to the person running it.');
            if ($link->max_candidates && ExamCandidate::where('exam_session_id', $link->exam_session_id)->count() >= $link->max_candidates) {
                return back()->with('error', 'This exam is full.');
            }
            $roster->add($link->session, ['identifiers' => $user->email]);
            $candidate = ExamCandidate::where('exam_session_id', $link->exam_session_id)->where('user_id', $user->id)->firstOrFail();
        }
        ExamLinkEntry::firstOrCreate(['exam_link_id' => $link->id, 'user_id' => $user->id], [
            'exam_candidate_id' => $candidate->id, 'guest' => false, 'name' => $user->name, 'email' => $user->email, 'identifier' => $user->identifier, 'ip' => $request->ip(),
        ]);

        return redirect()->route('candidate.room', $candidate);
    }

    /** Anyone with the link: an exam-only account, kept for this exam and this browser. */
    private function enterAsGuest(Request $request, ExamLink $link): RedirectResponse
    {
        $ask = (array) $link->ask;
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'identifier' => [in_array('identifier', $ask, true) ? 'required' : 'nullable', 'string', 'max:60'],
            'email' => [in_array('email', $ask, true) ? 'required' : 'nullable', 'email', 'max:190'],
            'phone' => [in_array('phone', $ask, true) ? 'required' : 'nullable', 'string', 'max:40'],
        ]);
        $secret = (string) $request->cookie($this->cookieName($link));
        $browser = $secret !== '' ? hash('sha256', $secret) : null;

        // the same browser coming back (closed the tab, crashed, network): straight back in
        $entry = $browser ? ExamLinkEntry::where('exam_link_id', $link->id)->where('browser_hash', $browser)->first() : null;

        // the same person (same number or email) on another browser: only when the organiser has let them
        $key = ! empty($data['identifier']) ? ['identifier', trim($data['identifier'])] : (! empty($data['email']) ? ['email', strtolower(trim($data['email']))] : null);
        if (! $entry && $key) {
            $known = ExamLinkEntry::where('exam_link_id', $link->id)->where($key[0], $key[1])->first();
            if ($known && $known->browser_hash) {
                throw ValidationException::withMessages([$key[0] => 'Someone has already entered with this '.($key[0] === 'email' ? 'email' : 'number').' on another computer. If that was you, ask the person running the exam to let you in again.']);
            }
            $entry = $known;
        }

        if (! $entry) {
            if ($link->max_candidates && ExamLinkEntry::where('exam_link_id', $link->id)->count() >= $link->max_candidates) {
                return back()->with('error', 'This exam is full.');
            }
            $entry = DB::transaction(function () use ($link, $data, $request) {
                $user = User::create([
                    'name' => trim($data['name']),
                    'email' => 'guest-'.Str::lower(Str::random(20)).'@exam-guest.invalid',
                    'password' => Hash::make(Str::random(40)),
                    'identifier' => 'G'.$link->id.'-'.Str::upper(Str::random(8)),
                ]);
                $user->forceFill(['user_type' => ExamLink::GUEST_TYPE, 'status' => 'active', 'email_verified_at' => now()])->save();
                $candidate = ExamCandidate::create(['exam_session_id' => $link->exam_session_id, 'user_id' => $user->id, 'eligibility' => 'eligible']);

                return ExamLinkEntry::create([
                    'exam_link_id' => $link->id, 'user_id' => $user->id, 'exam_candidate_id' => $candidate->id, 'guest' => true,
                    'name' => trim($data['name']), 'email' => ! empty($data['email']) ? strtolower(trim($data['email'])) : null,
                    'phone' => $data['phone'] ?? null, 'identifier' => ! empty($data['identifier']) ? trim($data['identifier']) : null, 'ip' => $request->ip(),
                ]);
            });
        }

        $secret = Str::random(40);
        $entry->forceFill(['browser_hash' => hash('sha256', $secret)])->save();
        $user = User::findOrFail($entry->user_id);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('exam_guest_entry', $entry->id);

        return redirect()->route('candidate.room', $entry->exam_candidate_id)
            ->withCookie(cookie($this->cookieName($link), $secret, 60 * 24 * 7, null, null, $request->isSecure(), true, false, 'lax'));
    }
}
