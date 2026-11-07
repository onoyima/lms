<?php

namespace App\Conferencing;

use App\Models\ConferenceServer;
use App\Models\Meeting;
use App\Models\MeetingRecording;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Events from a BigBlueButton server's webhooks (bbb-webhooks). Each server posts to its own address;
 * the request is accepted only with that server's checksum — sha(callback address + body + secret) —
 * or its secret as a bearer token, exactly as bbb-webhooks signs it.
 */
class WebhookHandler
{
    public function verify(Request $request, ConferenceServer $server): bool
    {
        $secret = (string) $server->secret;
        if ($bearer = $request->bearerToken()) {
            return hash_equals($secret, $bearer);
        }
        $given = (string) $request->query('checksum', '');
        $algo = match (strlen($given)) { 40 => 'sha1', 64 => 'sha256', 96 => 'sha384', 128 => 'sha512', default => null };
        if (! $algo) {
            return false;
        }
        // the address the hook was registered with = this request's address without the checksum parameter
        $query = preg_replace('/(^|&)checksum=[^&]*/', '', (string) $request->server('QUERY_STRING'));
        $query = trim((string) $query, '&');
        $callback = $request->url().($query !== '' ? '?'.$query : '');

        return hash_equals(hash($algo, $callback.$request->getContent().$secret), $given)
            || hash_equals(hash($algo, route('conferencing.webhook', $server->id).$request->getContent().$secret), $given);
    }

    /** @return int events stored (duplicates are ignored) */
    public function handle(Request $request, ConferenceServer $server): int
    {
        $raw = $request->input('event');
        if ($raw === null) { // read the signed body itself when the framework did not parse it
            parse_str($request->getContent(), $form);
            $raw = $form['event'] ?? '[]';
        }
        $events = json_decode((string) $raw, true);
        if (! is_array($events)) {
            return 0;
        }
        $stored = 0;
        $touched = [];
        foreach ($events as $raw) {
            $d = $raw['data'] ?? $raw;
            $type = (string) ($d['id'] ?? '');
            $attr = $d['attributes'] ?? [];
            $extMeeting = (string) ($attr['meeting']['external-meeting-id'] ?? '');
            if ($type === '' || $extMeeting === '') {
                continue;
            }
            $meeting = Meeting::withoutGlobalScopes()->where('bbb_meeting_id', $extMeeting)->first();
            // only meetings this server holds (an institution's server cannot report on another's meetings)
            if (! $meeting || ($server->tenant_id && $server->tenant_id !== $meeting->tenant_id)) {
                continue;
            }
            $user = $attr['user'] ?? [];
            $ext = (string) ($user['external-user-id'] ?? '');
            $ts = (int) ($d['event']['ts'] ?? 0);
            $at = $ts ? CarbonImmutable::createFromTimestampMs($ts) : CarbonImmutable::now();
            $key = hash('sha256', implode('|', [$extMeeting, $type, $user['internal-user-id'] ?? '', $attr['record-id'] ?? '', $ts]));
            $userId = preg_match('/^u(\d+)$/', $ext, $mm) ? (int) $mm[1] : null;
            if ($userId && ! DB::table('users')->where('id', $userId)->where('tenant_id', $meeting->tenant_id)->exists()) {
                $userId = null; // a user id from somewhere else is never trusted
            }
            $inserted = DB::table('meeting_events')->insertOrIgnore([
                'tenant_id' => $meeting->tenant_id, 'meeting_id' => $meeting->id, 'event_key' => $key, 'type' => mb_substr($type, 0, 40),
                'internal_user_id' => isset($user['internal-user-id']) ? mb_substr((string) $user['internal-user-id'], 0, 100) : null, 'user_id' => $userId,
                'occurred_at' => $at->format('Y-m-d H:i:s.v'),
                'payload' => json_encode(array_filter(['name' => $user['name'] ?? null, 'role' => $user['role'] ?? null, 'record_id' => $attr['record-id'] ?? null])),
                'created_at' => now(),
            ]);
            if (! $inserted) {
                continue;
            }
            $stored++;
            $touched[$meeting->id] = $meeting;
            app(TenantContext::class)->run($meeting->tenant()->first(), fn () => $this->apply($meeting, $type, $attr, $at));
        }
        foreach ($touched as $m) {
            app(TenantContext::class)->run($m->tenant()->first(), fn () => app(Attendance::class)->rebuild($m->fresh()));
        }

        return $stored;
    }

    private function apply(Meeting $m, string $type, array $attr, CarbonImmutable $at): void
    {
        match (true) {
            $type === 'meeting-created' && $m->status === 'scheduled' => $m->update(['status' => 'live', 'started_at' => $m->started_at ?? $at]),
            $type === 'meeting-ended' => $m->update(['status' => 'ended', 'ended_at' => $m->ended_at ?? $at]),
            str_starts_with($type, 'rap-') && ! empty($attr['record-id']) => $this->recordingEvent($m, $type, (string) $attr['record-id']),
            default => null,
        };
    }

    private function recordingEvent(Meeting $m, string $type, string $recordId): void
    {
        $state = match ($type) { 'rap-published', 'rap-publish-ended' => 'published', 'rap-unpublished' => 'unpublished', 'rap-deleted' => 'deleted', default => null };
        $rec = MeetingRecording::firstOrCreate(['record_id' => $recordId], ['meeting_id' => $m->id, 'state' => 'processing', 'visibility' => $m->setting('recording_visibility') ?: 'attendees']);
        if ($state) {
            $rec->update(['state' => $state]);
        }
        if ($state === 'published') {
            try {
                app(MeetingService::class)->syncRecordings($m);
            } catch (BbbException) {
                // picked up by the next recording sync
            }
        }
    }
}
