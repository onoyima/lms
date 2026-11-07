<?php

namespace App\Http\Controllers\Conferencing;

use App\Conferencing\BuiltinRoom;
use App\Conferencing\MeetingAccess;
use App\Conferencing\MeetingService;
use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\MeetingGuest;
use App\Models\MeetingGuestLink;
use App\Models\MeetingPeer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The built-in meeting room, for signed-in people (/meetings/{meeting}/room/...) and for guests who came
 * through a guest link (/join/{token}/room/...). The same endpoints serve both; only who-you-are differs.
 */
class RoomController extends Controller
{
    public function __construct(private BuiltinRoom $room, private MeetingAccess $access, private MeetingService $meetings) {}

    /** Who is asking: [meeting, user|null, guest|null, display name, moderator?] */
    private function actor(Request $request): array
    {
        if ($token = $request->route('token')) {
            $link = MeetingGuestLink::with('meeting')->where('token', $token)->where('enabled', true)->firstOrFail();
            $guestId = (int) $request->session()->get('meeting_guest.'.$link->meeting_id);
            $guest = $guestId ? MeetingGuest::where('meeting_id', $link->meeting_id)->find($guestId) : null;
            abort_unless($guest, 403, 'Open the guest link again and type your name.');

            return [$link->meeting, null, $guest, $guest->name.' (guest)', false];
        }
        $m = $request->route('meeting');
        $m = $m instanceof Meeting ? $m : Meeting::where('uuid', $m)->firstOrFail();
        $user = $request->user();
        abort_unless($this->access->joins($user, $m), 403, 'You are not invited to this meeting.');

        return [$m, $user, null, $user->name, $this->access->moderates($user, $m)];
    }

    private function sessionKey(Meeting $m): string
    {
        return 'meeting_peer.'.$m->id;
    }

    private function me(Request $request): MeetingPeer
    {
        [$m] = $this->actor($request);
        $id = (string) $request->session()->get($this->sessionKey($m));
        abort_if($id === '', 409, 'Open the meeting room again.');

        return MeetingPeer::where('meeting_id', $m->id)->where('peer_id', $id)->firstOrFail();
    }

    public function show(Request $request): \Inertia\Response|HttpResponse
    {
        [$m, $user, $guest, $name, $moderator] = $this->actor($request);
        $back = $guest ? route('meetings.guest', $request->route('token')) : route('meetings.show', $m);
        if ($this->room->engine($m) !== 'builtin') {
            return redirect($back);
        }
        [$open, $why] = $this->meetings->window($m, $moderator);
        if (! $open) {
            return redirect($back)->with('error', $why);
        }
        if (! $moderator && $m->status !== 'live' && ! $m->setting('join_before_host')) {
            return redirect($back)->with('error', 'The meeting has not been started by the host yet. The page will let you in when it starts.');
        }
        $previous = $request->session()->get($this->sessionKey($m));
        if ($previous && MeetingPeer::where('meeting_id', $m->id)->where('peer_id', $previous)->whereIn('state', ['removed', 'refused'])->exists()) {
            return redirect($back)->with('error', 'The host did not let you into this meeting.');
        }
        $peer = $this->room->enter($m, $user, $guest, $name, $moderator, $previous);
        if (! $peer) {
            return redirect($back)->with('error', 'This room is full: built-in rooms take up to '.$this->room->max().' people. Ask the host to use the meeting server for larger meetings.');
        }
        $request->session()->put($this->sessionKey($m), $peer->peer_id);
        $base = $guest ? '/join/'.$request->route('token').'/room' : '/meetings/'.$m->uuid.'/room';

        return Inertia::render('meetings/room', [
            'meeting' => ['title' => $m->title, 'starts_at' => $m->starts_at, 'ends_at' => $m->endsAt(), 'welcome' => $m->setting('welcome'),
                'lock_cameras' => (bool) $m->setting('lock_cameras'), 'lock_microphones' => (bool) $m->setting('lock_microphones'),
                'lock_chat' => (bool) $m->setting('lock_public_chat'), 'mute_on_start' => (bool) $m->setting('mute_on_start')],
            'me' => $peer->card() + ['moderator' => $peer->moderates(), 'state' => $peer->state],
            'ice' => $this->room->iceServers($peer->peer_id),
            'endpoints' => ['base' => $base, 'exit' => $back, 'end' => $peer->moderates() && ! $guest ? '/meetings/'.$m->uuid.'/end' : null],
            'max' => $this->room->max(),
            'pollMs' => (int) config('conferencing.builtin.poll_ms', 1500),
            'csrf' => csrf_token(),
        ]);
    }

    public function poll(Request $request): JsonResponse
    {
        $data = $request->validate(['after' => ['nullable', 'integer', 'min:0'], 'audio' => ['boolean'], 'video' => ['boolean'], 'hand' => ['boolean'], 'screen' => ['boolean']]);

        return response()->json($this->room->poll($this->me($request), $data, (int) ($data['after'] ?? 0)))->header('Cache-Control', 'no-store');
    }

    public function signal(Request $request): JsonResponse
    {
        $data = $request->validate(['to' => ['nullable', 'string', 'size:24'], 'type' => ['required', 'string', 'max:20'], 'payload' => ['nullable', 'string']]);
        $this->room->signal($this->me($request), $data['to'] ?? null, $data['type'], $data['payload'] ?? null);

        return response()->json(['ok' => true]);
    }

    public function admit(Request $request): JsonResponse
    {
        $data = $request->validate(['peer' => ['required', 'string', 'size:24'], 'admit' => ['required', 'boolean']]);
        $this->room->admit($this->me($request), $data['peer'], (bool) $data['admit']);

        return response()->json(['ok' => true]);
    }

    public function remove(Request $request): JsonResponse
    {
        $data = $request->validate(['peer' => ['required', 'string', 'size:24']]);
        $this->room->remove($this->me($request), $data['peer']);

        return response()->json(['ok' => true]);
    }

    public function leave(Request $request): JsonResponse
    {
        $me = $this->me($request);
        if (in_array($me->state, ['in', 'waiting'], true)) {
            $this->room->leave($me, 'left');
        }

        return response()->json(['ok' => true]);
    }
}
