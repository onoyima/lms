<?php

namespace App\Conferencing;

use App\Models\ConferenceServer;
use App\Models\Meeting;
use App\Models\MeetingGuest;
use App\Models\MeetingPeer;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Built-in meeting rooms. Browsers send audio and video straight to each other (WebRTC); this class only
 * keeps the list of who is in the room and passes their connection details (offers, answers, network
 * candidates) between them, plus chat, raised hands and the host's controls. Nothing here needs a
 * meeting server, so it works on ordinary web hosting. Attendance is written as meeting events, so the
 * attendance list and the course reports work exactly as for the meeting server.
 */
class BuiltinRoom
{
    private const TYPES = ['offer', 'answer', 'candidate', 'chat', 'mute', 'restart'];

    public function __construct(private AuditLogger $audit) {}

    /** Where this meeting runs: "builtin" or "bbb". A room that is already open keeps its engine. */
    public function engine(Meeting $m): string
    {
        if ($m->status === 'live') {
            if ($m->conference_server_id) {
                return 'bbb';
            }
            if (MeetingPeer::where('meeting_id', $m->id)->exists()) {
                return 'builtin';
            }
        }

        return match ($m->setting('engine') ?? 'auto') {
            'builtin' => 'builtin',
            'bbb' => 'bbb',
            default => ConferenceServer::usableBy($m->tenant_id)->exists() ? 'bbb' : 'builtin',
        };
    }

    public function max(): int
    {
        return max(2, (int) config('conferencing.builtin.max_participants', 6));
    }

    /** STUN (and TURN, if set up) for the browser. TURN passwords are short-lived when a shared secret is used. */
    public function iceServers(string $peerId): array
    {
        $out = [];
        $stun = (array) config('conferencing.builtin.stun', []);
        if ($stun) {
            $out[] = ['urls' => $stun];
        }
        $turn = (array) config('conferencing.builtin.turn_urls', []);
        if ($turn) {
            if ($secret = (string) config('conferencing.builtin.turn_secret')) {
                $user = (time() + 6 * 3600).':'.$peerId;
                $out[] = ['urls' => $turn, 'username' => $user, 'credential' => base64_encode(hash_hmac('sha1', $user, $secret, true))];
            } else {
                $out[] = ['urls' => $turn, 'username' => (string) config('conferencing.builtin.turn_username'), 'credential' => (string) config('conferencing.builtin.turn_password')];
            }
        }

        return $out;
    }

    /**
     * Put this person in the room (or the waiting room). One browser per person: opening the room again
     * elsewhere replaces the older one. Returns the peer, or null when the room is full.
     */
    public function enter(Meeting $m, ?User $user, ?MeetingGuest $guest, string $name, bool $moderator, ?string $previousPeer): ?MeetingPeer
    {
        return DB::transaction(function () use ($m, $user, $guest, $name, $moderator, $previousPeer) {
            Meeting::whereKey($m->id)->lockForUpdate()->first(); // one entry at a time per room
            $this->sweep($m);

            // the same browser coming back (refresh): keep its place
            if ($previousPeer && ($p = MeetingPeer::where('meeting_id', $m->id)->where('peer_id', $previousPeer)->whereIn('state', ['in', 'waiting'])->first())) {
                $p->forceFill(['last_seen_at' => now()])->save();

                return $p;
            }
            // one place per person: an older browser of theirs leaves
            $mine = MeetingPeer::where('meeting_id', $m->id)->whereIn('state', ['in', 'waiting'])
                ->when($user, fn ($q) => $q->where('user_id', $user->id), fn ($q) => $q->where('meeting_guest_id', $guest?->id))->get();
            foreach ($mine as $old) {
                $this->leave($old, 'left');
            }

            $present = MeetingPeer::where('meeting_id', $m->id)->whereIn('state', ['in', 'waiting'])->count();
            if ($present >= $this->max()) {
                return null;
            }
            $waiting = ! $moderator && ($guest !== null || $m->setting('waiting_room'));
            $role = $guest ? 'guest' : ($moderator ? ($m->created_by === $user?->id ? 'host' : 'moderator') : 'participant');
            $peer = MeetingPeer::create([
                'meeting_id' => $m->id, 'peer_id' => Str::random(24), 'user_id' => $user?->id, 'meeting_guest_id' => $guest?->id,
                'name' => Str::limit(trim($name) ?: 'Guest', 120, ''), 'role' => $role, 'state' => $waiting ? 'waiting' : 'in',
                'audio' => ! $m->setting('mute_on_start') || $moderator, 'video' => true,
                'joined_at' => $waiting ? null : now(), 'last_seen_at' => now(),
            ]);
            if ($moderator && $m->status === 'scheduled') {
                $m->update(['status' => 'live', 'started_at' => now()]);
                $this->audit->record('meeting.started', $m, ['room' => 'built-in']);
            }
            if (! $waiting) {
                $this->event($peer, 'user-joined');
            }

            return $peer;
        });
    }

    /** A browser checks in: its microphone/camera/hand state, and what has arrived for it since last time. */
    public function poll(MeetingPeer $me, array $flags, int $after): array
    {
        $m = Meeting::withoutGlobalScopes()->findOrFail($me->meeting_id);
        if (in_array($me->state, ['in', 'waiting'], true)) {
            $me->forceFill(array_intersect_key(array_map('boolval', $flags), array_flip(['audio', 'video', 'hand', 'screen'])) + ['last_seen_at' => now()])->save();
        }
        if ($m->status === 'ended' && $me->state === 'in') {
            $this->leave($me, 'left');
        }
        if (random_int(1, 4) === 1) {
            $this->sweep($m); // cheap, but no need on every check-in
        }
        $peers = MeetingPeer::where('meeting_id', $m->id)->whereIn('state', ['in', 'waiting'])->orderBy('id')->get();
        $signals = $me->state === 'in'
            ? DB::table('meeting_signals')->where('meeting_id', $m->id)->where('id', '>', $after)
                ->where(fn ($q) => $q->where('to_peer', $me->peer_id)->orWhere(fn ($b) => $b->whereNull('to_peer')->where('from_peer', '!=', $me->peer_id)))
                ->orderBy('id')->limit(300)->get(['id', 'from_peer', 'type', 'payload'])
            : collect();
        $last = $signals->max('id') ?? ($after ?: (int) DB::table('meeting_signals')->where('meeting_id', $m->id)->max('id'));

        return [
            'state' => $me->state,
            'meeting' => $m->status,
            'peers' => $me->state === 'in' ? $peers->where('state', 'in')->where('peer_id', '!=', $me->peer_id)->map->card()->values() : [],
            'waiting' => $me->moderates() ? $peers->where('state', 'waiting')->map(fn ($p) => $p->card())->values() : [],
            'signals' => $signals->map(fn ($s) => ['id' => $s->id, 'from' => $s->from_peer, 'type' => $s->type, 'payload' => $s->payload])->values(),
            'last' => (int) $last,
            'me' => $me->card(),
        ];
    }

    /** Pass something to one browser, or (chat, mute by the host) to everyone. */
    public function signal(MeetingPeer $from, ?string $to, string $type, ?string $payload): void
    {
        abort_unless($from->state === 'in', 409, 'You are not in the room.');
        abort_unless(in_array($type, self::TYPES, true), 422);
        abort_if($type === 'mute' && ! $from->moderates(), 403, 'Only the host can mute others.');
        abort_if(in_array($type, ['offer', 'answer', 'candidate', 'restart'], true) && ! $to, 422);
        abort_if(strlen((string) $payload) > 120_000, 413);
        if ($type === 'chat') {
            $m = Meeting::withoutGlobalScopes()->find($from->meeting_id);
            abort_if($m?->setting('lock_public_chat') && ! $from->moderates(), 403, 'Chat is switched off for this meeting.');
            $payload = json_encode(['name' => $from->name, 'text' => mb_substr(trim((string) $payload), 0, 1000), 'at' => now()->toIso8601String()]);
        }
        if ($to) {
            abort_unless(MeetingPeer::where('meeting_id', $from->meeting_id)->where('peer_id', $to)->where('state', 'in')->exists(), 404);
        }
        DB::table('meeting_signals')->insert(['meeting_id' => $from->meeting_id, 'from_peer' => $from->peer_id, 'to_peer' => $to, 'type' => $type, 'payload' => $payload, 'created_at' => now()]);
    }

    /** The host lets someone in from the waiting room, or turns them away. */
    public function admit(MeetingPeer $host, string $peerId, bool $yes): void
    {
        abort_unless($host->moderates() && $host->state === 'in', 403);
        $p = MeetingPeer::where('meeting_id', $host->meeting_id)->where('peer_id', $peerId)->where('state', 'waiting')->firstOrFail();
        $p->forceFill($yes ? ['state' => 'in', 'joined_at' => now()] : ['state' => 'refused', 'left_at' => now()])->save();
        if ($yes) {
            $this->event($p, 'user-joined');
        }
    }

    public function remove(MeetingPeer $host, string $peerId): void
    {
        abort_unless($host->moderates() && $host->state === 'in', 403);
        $p = MeetingPeer::where('meeting_id', $host->meeting_id)->where('peer_id', $peerId)->whereIn('state', ['in', 'waiting'])->firstOrFail();
        abort_if($p->role === 'host', 403, 'The host cannot be removed.');
        $this->leave($p, 'removed');
        $this->audit->record('meeting.participant_removed', Meeting::withoutGlobalScopes()->find($host->meeting_id), ['name' => $p->name]);
    }

    public function leave(MeetingPeer $p, string $state = 'left'): void
    {
        $wasIn = $p->state === 'in';
        $p->forceFill(['state' => $state, 'left_at' => now(), 'hand' => false])->save();
        if ($wasIn) {
            $this->event($p, 'user-left');
        }
    }

    /** Browsers that stopped checking in (closed laptop, lost network) leave the room. */
    public function sweep(Meeting $m): void
    {
        $gone = MeetingPeer::where('meeting_id', $m->id)->whereIn('state', ['in', 'waiting'])
            ->where('last_seen_at', '<', now()->subSeconds((int) config('conferencing.builtin.gone_after', 25)))->get();
        foreach ($gone as $p) {
            $this->leave($p, 'left');
        }
    }

    /** End the room for everyone (the meeting's End button). */
    public function close(Meeting $m): void
    {
        foreach (MeetingPeer::where('meeting_id', $m->id)->whereIn('state', ['in', 'waiting'])->get() as $p) {
            $this->leave($p, 'left');
        }
        DB::table('meeting_signals')->where('meeting_id', $m->id)->delete();
    }

    /** Join and leave times become meeting events, so attendance works the same as on the meeting server. */
    private function event(MeetingPeer $p, string $type): void
    {
        $at = now();
        DB::table('meeting_events')->insertOrIgnore([
            'tenant_id' => $p->tenant_id, 'meeting_id' => $p->meeting_id,
            'event_key' => hash('sha256', 'builtin|'.$p->peer_id.'|'.$type.'|'.$at->format('U.u')),
            'type' => $type, 'internal_user_id' => 'p'.$p->peer_id, 'user_id' => $p->user_id,
            'occurred_at' => $at, 'payload' => json_encode(['name' => $p->name, 'role' => $p->role === 'host' || $p->role === 'moderator' ? 'MODERATOR' : 'VIEWER', 'room' => 'built-in', 'guest' => $p->meeting_guest_id !== null]),
            'created_at' => $at,
        ]);
        app(Attendance::class)->rebuild(Meeting::withoutGlobalScopes()->findOrFail($p->meeting_id));
    }
}
