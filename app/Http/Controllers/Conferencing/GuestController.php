<?php

namespace App\Http\Controllers\Conferencing;

use App\Conferencing\BbbException;
use App\Conferencing\BuiltinRoom;
use App\Conferencing\MeetingAccess;
use App\Conferencing\MeetingService;
use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\MeetingGuest;
use App\Models\MeetingGuestLink;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Guest links: people without an account (an external examiner, a guest speaker, a parent) join a meeting
 * with the name they type, through the waiting room, and only that meeting. The host makes the link.
 */
class GuestController extends Controller
{
    public function __construct(private MeetingAccess $access, private AuditLogger $audit) {}

    // ── the host's side ────────────────────────────────────────────────────

    public function save(Request $request, Meeting $meeting): RedirectResponse
    {
        abort_unless($this->access->moderates($request->user(), $meeting), 403);
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'passcode' => ['nullable', 'string', 'min:4', 'max:40'], 'new_link' => ['boolean']]);
        $link = MeetingGuestLink::firstOrNew(['meeting_id' => $meeting->id]);
        if (! $link->exists || ($data['new_link'] ?? false)) {
            $link->token = Str::random(32);
        }
        $link->enabled = (bool) $data['enabled'];
        if (array_key_exists('passcode', $data)) {
            $link->passcode_hash = $data['passcode'] ? Hash::make($data['passcode']) : null;
        }
        $link->created_by ??= $request->user()->id;
        $link->save();
        $this->audit->record('meeting.guest_link', $meeting, ['enabled' => $link->enabled, 'passcode' => (bool) $link->passcode_hash, 'new' => (bool) ($data['new_link'] ?? false)]);

        return back()->with('success', $link->enabled ? 'Guest link ready. Anyone with it can ask to join; they wait until a host lets them in.' : 'Guest link switched off.');
    }

    // ── the guest's side (no account) ─────────────────────────────────────

    private function link(string $token): MeetingGuestLink
    {
        return MeetingGuestLink::with('meeting')->where('token', $token)->where('enabled', true)->firstOrFail();
    }

    public function show(Request $request, string $token): Response
    {
        $link = $this->link($token);
        $m = $link->meeting;
        $guestId = (int) $request->session()->get('meeting_guest.'.$m->id);
        [$open, $why] = app(MeetingService::class)->window($m, false);

        return Inertia::render('meetings/guest', [
            'meeting' => ['title' => $m->title, 'starts_at' => $m->starts_at, 'ends_at' => $m->endsAt(), 'status' => $m->status, 'timezone' => $m->timezone,
                'description' => $m->description, 'institution' => $m->tenant?->name],
            'needsPasscode' => (bool) $link->passcode_hash,
            'known' => $guestId ? MeetingGuest::where('meeting_id', $m->id)->find($guestId)?->only(['name']) : null,
            'open' => $open && ($m->status === 'live' || $m->setting('join_before_host')),
            'notice' => $open ? ($m->status !== 'live' && ! $m->setting('join_before_host') ? 'The host has not started the meeting yet. Keep this page open; it lets you in when it starts.' : null) : $why,
            'token' => $token,
        ]);
    }

    public function status(string $token): \Illuminate\Http\JsonResponse
    {
        $m = $this->link($token)->meeting;

        return response()->json(['status' => $m->status]);
    }

    public function join(Request $request, string $token): HttpResponse
    {
        $link = $this->link($token);
        $m = $link->meeting;
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:80'], 'email' => ['nullable', 'email', 'max:190'], 'passcode' => ['nullable', 'string', 'max:40']]);
        if ($link->passcode_hash && ! Hash::check((string) ($data['passcode'] ?? ''), $link->passcode_hash)) {
            return back()->withErrors(['passcode' => 'That passcode is not right.'])->withInput();
        }
        $guestId = (int) $request->session()->get('meeting_guest.'.$m->id);
        $guest = $guestId ? MeetingGuest::where('meeting_id', $m->id)->find($guestId) : null;
        if (! $guest || $guest->name !== trim($data['name'])) {
            $guest = MeetingGuest::create(['meeting_id' => $m->id, 'name' => trim(strip_tags($data['name'])), 'email' => $data['email'] ?? null,
                'ip' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 255)]);
            $request->session()->put('meeting_guest.'.$m->id, $guest->id);
            $this->audit->record('meeting.guest_joined', $m, ['guest' => $guest->name]);
        }

        if (app(BuiltinRoom::class)->engine($m) === 'builtin') {
            return redirect()->route('meetings.guest.room', $token);
        }
        try {
            $url = app(MeetingService::class)->guestJoinUrl($m, $guest);
        } catch (BbbException|\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return back()->with('error', $e->getMessage());
        }

        return Inertia::location($url);
    }
}
