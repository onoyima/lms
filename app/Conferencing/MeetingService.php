<?php

namespace App\Conferencing;

use App\Models\ConferenceServer;
use App\Models\CourseOffering;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Platform\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/** Creating, scheduling, starting, joining and ending meetings. All BigBlueButton calls go through BbbClient. */
class MeetingService
{
    public function __construct(private AuditLogger $audit, private MeetingAccess $access) {}

    /** Create one meeting, or a weekly series (one row per occurrence, linked to the first). */
    public function create(array $data, User $by): Meeting
    {
        return DB::transaction(function () use ($data, $by) {
            $start = CarbonImmutable::parse($data['starts_at'], $data['timezone'] ?? 'Africa/Lagos')->utc();
            $first = $this->make($data, $start, $by, null);
            $rule = $data['recurrence'] ?? null;
            if ($rule && ! empty($rule['days']) && ! empty($rule['until'])) {
                $first->update(['recurrence' => ['days' => array_values(array_map('intval', $rule['days'])), 'until' => $rule['until']]]);
                $local = $start->setTimezone($data['timezone'] ?? 'Africa/Lagos');
                $until = CarbonImmutable::parse($rule['until'], $data['timezone'] ?? 'Africa/Lagos')->endOfDay();
                $day = $local->addDay()->startOfDay();
                $made = 0;
                while ($day->lte($until) && $made < 200) {
                    if (in_array($day->dayOfWeekIso, $first->recurrence['days'], true)) {
                        $this->make($data, $day->setTime($local->hour, $local->minute)->utc(), $by, $first->id);
                        $made++;
                    }
                    $day = $day->addDay();
                }
            }
            $this->audit->record('meeting.created', $first, ['title' => $first->title, 'type' => $first->type, 'series' => (bool) $rule]);

            return $first;
        });
    }

    private function make(array $d, CarbonImmutable $start, User $by, ?int $seriesId): Meeting
    {
        $m = Meeting::create([
            'uuid' => (string) Str::uuid(), 'bbb_meeting_id' => 'm-'.Str::lower(Str::random(24)), 'course_offering_id' => $d['course_offering_id'] ?? null,
            'series_id' => $seriesId, 'type' => $d['type'] ?? 'class', 'title' => $d['title'], 'description' => $d['description'] ?? null,
            'starts_at' => $start, 'duration_minutes' => (int) ($d['duration_minutes'] ?? 60), 'timezone' => $d['timezone'] ?? 'Africa/Lagos',
            'access' => $d['access'] ?? (! empty($d['course_offering_id']) ? 'course' : 'invited'),
            'settings' => array_intersect_key(($d['settings'] ?? []) + Meeting::DEFAULT_SETTINGS, Meeting::DEFAULT_SETTINGS),
            'attendee_pw' => Str::random(16), 'moderator_pw' => Str::random(16), 'created_by' => $by->id,
        ]);
        MeetingParticipant::create(['meeting_id' => $m->id, 'user_id' => $by->id, 'role' => 'host', 'added_by' => $by->id]);
        foreach ($d['invite'] ?? [] as $row) {
            MeetingParticipant::firstOrCreate(['meeting_id' => $m->id, 'user_id' => $row['user_id']], ['role' => $row['role'] ?? 'participant', 'added_by' => $by->id]);
        }

        return $m;
    }

    /** Pick the least busy usable server (the institution's own first). */
    public function serverFor(Meeting $m): ConferenceServer
    {
        if ($m->server && $m->server->enabled && ($m->server->status !== 'offline' || $m->status === 'live')) {
            return $m->server;
        }
        $servers = ConferenceServer::usableBy($m->tenant_id)->get();
        abort_if($servers->isEmpty(), 503, 'No meeting server is set up yet. Ask the platform team to add a BigBlueButton server.');
        $own = $servers->whereNotNull('tenant_id');
        $pool = ($own->isNotEmpty() ? $own : $servers)->filter(fn ($s) => $s->status !== 'offline');
        $server = ($pool->isNotEmpty() ? $pool : $servers)->sortBy(fn ($s) => $s->live_participants / max(1, $s->weight))->first();
        $m->update(['conference_server_id' => $server->id]);

        return $server;
    }

    /** Can this person open the room now? */
    public function window(Meeting $m, bool $moderator): array
    {
        $now = now();
        $opens = $m->starts_at->copy()->subMinutes($moderator ? (int) config('conferencing.open_minutes_before') : (int) $m->setting('early_join_minutes'));
        $closes = $m->endsAt()->copy()->addMinutes((int) config('conferencing.close_minutes_after'));

        return match (true) {
            $m->status === 'cancelled' => [false, 'This meeting was cancelled.'],
            $m->status === 'ended' => [false, 'This meeting has ended.'],
            $m->status === 'live' => [true, null],
            $now->lt($opens) => [false, 'The room opens at '.$opens->setTimezone($m->timezone)->format('D j M, H:i').'.'],
            $now->gt($closes) => [false, 'This meeting is over.'],
            default => [true, null],
        };
    }

    /** Create the room on BigBlueButton (idempotent) with the institution's branding and the meeting's rules. */
    public function ensureRoom(Meeting $m): array
    {
        $server = $this->serverFor($m);
        $bbb = BbbClient::for($server);
        $tenant = $m->tenant()->first();
        $brand = $tenant?->brandPayload() ?? PlatformSettings::brand();
        $waiting = (bool) $m->setting('waiting_room');
        $params = [
            'name' => Str::limit($m->title, 250, ''), 'meetingID' => $m->bbb_meeting_id,
            'attendeePW' => $m->attendee_pw, 'moderatorPW' => $m->moderator_pw,
            'welcome' => $m->setting('welcome') ?: 'Welcome to '.$m->title.'.',
            'record' => $m->setting('record') ? 'true' : 'false', 'autoStartRecording' => $m->setting('auto_record') ? 'true' : 'false', 'allowStartStopRecording' => 'true',
            'duration' => $m->duration_minutes + (int) config('conferencing.close_minutes_after'),
            'maxParticipants' => $m->setting('max_participants') ?: null,
            'muteOnStart' => $m->setting('mute_on_start') ? 'true' : 'false',
            'guestPolicy' => $waiting ? 'ASK_MODERATOR' : 'ALWAYS_ACCEPT',
            'webcamsOnlyForModerator' => $m->setting('webcams_only_for_moderator') ? 'true' : 'false',
            'lockSettingsDisableCam' => $m->setting('lock_cameras') ? 'true' : 'false',
            'lockSettingsDisableMic' => $m->setting('lock_microphones') ? 'true' : 'false',
            'lockSettingsDisablePublicChat' => $m->setting('lock_public_chat') ? 'true' : 'false',
            'lockSettingsDisablePrivateChat' => $m->setting('lock_private_chat') ? 'true' : 'false',
            'lockSettingsHideUserList' => $m->setting('hide_user_list') ? 'true' : 'false',
            'meetingLayout' => in_array($m->setting('layout'), Meeting::LAYOUTS, true) ? $m->setting('layout') : 'SMART_LAYOUT',
            'disabledFeatures' => implode(',', array_values(array_intersect((array) $m->setting('disabled_features'), array_keys(Meeting::FEATURES)))) ?: null,
            'logoutURL' => route('meetings.show', $m),
            'logo' => $brand['logo_url'] ?? null,
            'bannerText' => $tenant?->name,
            'bannerColor' => $brand['brand'] ?? null,
            'meta_platform-tenant' => (string) $m->tenant_id, 'meta_platform-meeting' => $m->uuid, 'meta_bbb-origin' => 'platform',
            'meta_endCallbackUrl' => URL::signedRoute('conferencing.ended', ['meeting' => $m->uuid]),
            'meta_bbb-recording-ready-url' => URL::signedRoute('conferencing.recording-ready', ['meeting' => $m->uuid]),
        ];
        $xml = $bbb->create($params, $this->slidesXml($m));
        if ($m->status === 'scheduled') {
            $m->update(['status' => 'live', 'started_at' => now()]);
            $this->audit->record('meeting.started', $m, ['server' => $server->name]);
        }

        return ['bbb' => $bbb, 'createTime' => (string) $xml->createTime];
    }

    /** Uploaded slides, offered to BigBlueButton through short-lived signed links (it downloads them itself). */
    public function slidesXml(Meeting $m): ?string
    {
        $slides = array_values((array) $m->setting('slides'));
        if (! $slides) {
            return null;
        }
        $docs = '';
        foreach ($slides as $i => $s) {
            $url = URL::temporarySignedRoute('conferencing.slide', now()->addHours(6), ['meeting' => $m->uuid, 'index' => $i]);
            $docs .= '<document url="'.htmlspecialchars($url, ENT_XML1 | ENT_QUOTES).'" filename="'.htmlspecialchars($s['name'] ?? 'slides.pdf', ENT_XML1 | ENT_QUOTES).'"'.($i === 0 ? ' current="true"' : '').'/>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?><modules><module name="presentation">'.$docs.'</module></modules>';
    }

    /** A one-time join address for this person, with their role and the institution's look. */
    public function joinUrl(Meeting $m, User $user): string
    {
        $moderator = $this->access->moderates($user, $m);
        [$open, $why] = $this->window($m, $moderator);
        abort_unless($open, 409, $why);
        if (! $moderator && $m->status !== 'live' && ! $m->setting('join_before_host')) {
            abort(409, 'The meeting has not been started by the host yet. This page refreshes when it starts.');
        }
        ['bbb' => $bbb, 'createTime' => $createTime] = $this->ensureRoom($m);
        $role = $this->access->role($user, $m);
        $params = [
            'fullName' => Str::limit($user->name, 100, ''), 'meetingID' => $m->bbb_meeting_id,
            'role' => $moderator ? 'MODERATOR' : 'VIEWER', 'password' => $moderator ? $m->moderator_pw : $m->attendee_pw,
            'userID' => 'u'.$user->id, 'createTime' => $createTime ?: null,
            'guest' => ! $moderator && $m->setting('waiting_room') ? 'true' : null,
            'userdata-bbb_custom_style_url' => route('conferencing.theme', ['tenant' => $m->tenant_id, 'v' => substr(md5((string) json_encode($m->tenant?->brandPayload())), 0, 8)]),
            'userdata-bbb_display_branding_area' => 'true',
            'userdata-bbb_auto_join_audio' => 'true',
            'userdata-bbb_listen_only_mode' => $m->setting('listen_only') ? 'true' : null,
            'userdata-bbb_skip_check_audio' => $m->setting('skip_audio_check') ? 'true' : null,
            'userdata-platform_role' => $role ?? ($moderator ? 'host' : 'participant'),
        ];
        $this->audit->record('meeting.joined', $m, ['as' => $moderator ? 'moderator' : 'viewer']);

        return $bbb->joinUrl($params);
    }

    /** A join address on the meeting server for someone who came through the guest link. */
    public function guestJoinUrl(Meeting $m, \App\Models\MeetingGuest $guest): string
    {
        [$open, $why] = $this->window($m, false);
        abort_unless($open, 409, $why);
        if ($m->status !== 'live' && ! $m->setting('join_before_host')) {
            abort(409, 'The meeting has not been started by the host yet. This page refreshes when it starts.');
        }
        ['bbb' => $bbb, 'createTime' => $createTime] = $this->ensureRoom($m);

        return $bbb->joinUrl([
            'fullName' => Str::limit($guest->name.' (guest)', 100, ''), 'meetingID' => $m->bbb_meeting_id,
            'role' => 'VIEWER', 'password' => $m->attendee_pw, 'userID' => 'g'.$guest->id, 'createTime' => $createTime ?: null,
            'guest' => 'true', // guests always pass the waiting room
            'userdata-bbb_custom_style_url' => route('conferencing.theme', ['tenant' => $m->tenant_id, 'v' => substr(md5((string) json_encode($m->tenant?->brandPayload())), 0, 8)]),
            'userdata-bbb_display_branding_area' => 'true',
            'userdata-bbb_listen_only_mode' => $m->setting('listen_only') ? 'true' : null,
            'userdata-platform_role' => 'guest',
        ]);
    }

    public function end(Meeting $m, User $by): void
    {
        if ($m->server) {
            BbbClient::for($m->server)->end($m->bbb_meeting_id);
        }
        app(BuiltinRoom::class)->close($m); // a built-in room empties too
        $m->update(['status' => 'ended', 'ended_at' => $m->ended_at ?? now()]);
        app(Attendance::class)->rebuild($m);
        $this->audit->record('meeting.ended', $m, ['by' => $by->id]);
    }

    public function cancel(Meeting $m, User $by, bool $wholeSeries = false): int
    {
        $q = Meeting::where('status', 'scheduled')->where(fn ($w) => $w->whereKey($m->id)->when($wholeSeries, fn ($x) => $x->orWhere('series_id', $m->series_id ?? $m->id)->orWhere('id', $m->series_id)));
        $n = $q->update(['status' => 'cancelled']);
        $this->audit->record('meeting.cancelled', $m, ['series' => $wholeSeries, 'count' => $n]);

        return $n;
    }

    /** Fetch the meeting's recordings from BigBlueButton and keep our list in step. */
    public function syncRecordings(Meeting $m): int
    {
        if (! $m->server) {
            return 0;
        }
        $n = 0;
        foreach (BbbClient::for($m->server)->recordings([$m->bbb_meeting_id]) as $r) {
            $format = $r->playback->format ?? null;
            $url = $format ? (string) ($format->url ?? '') : '';
            $state = in_array((string) $r->state, ['published', 'unpublished', 'deleted'], true) ? (string) $r->state : 'processing';
            \App\Models\MeetingRecording::updateOrCreate(['record_id' => (string) $r->recordID], [
                'meeting_id' => $m->id, 'state' => $state, 'format' => $format ? (string) $format->type : null, 'playback_url' => $url ?: null,
                'duration_seconds' => $format && (int) $format->length ? (int) $format->length * 60 : null,
                'size' => (int) ($r->size ?? 0) ?: null, 'participants' => (int) ($r->participants ?? 0) ?: null,
                'started_at' => (int) $r->startTime ? CarbonImmutable::createFromTimestampMs((int) $r->startTime) : null,
                'ended_at' => (int) $r->endTime ? CarbonImmutable::createFromTimestampMs((int) $r->endTime) : null,
                'visibility' => $m->setting('recording_visibility') ?: 'attendees',
            ]);
            $n++;
        }

        return $n;
    }
}
