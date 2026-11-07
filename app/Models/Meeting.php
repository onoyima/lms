<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A live meeting or virtual class on BigBlueButton. */
class Meeting extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const TYPES = [
        'class' => 'Live class', 'office_hours' => 'Office hours', 'tutorial' => 'Tutorial', 'seminar' => 'Seminar', 'webinar' => 'Webinar',
        'department' => 'Department meeting', 'faculty' => 'Faculty meeting', 'viva' => 'Viva', 'practical' => 'Practical session',
        'supervision' => 'Exam supervision', 'private' => 'Private meeting',
    ];

    public const ROLES = ['host', 'cohost', 'presenter', 'participant', 'examiner', 'proctor'];

    /** Meeting roles that join BigBlueButton as moderators. */
    public const MODERATOR_ROLES = ['host', 'cohost', 'examiner', 'proctor'];

    protected $fillable = ['uuid', 'bbb_meeting_id', 'conference_server_id', 'course_offering_id', 'series_id', 'type', 'title', 'description', 'starts_at', 'duration_minutes',
        'timezone', 'recurrence', 'access', 'settings', 'status', 'attendee_pw', 'moderator_pw', 'created_by', 'started_at', 'ended_at'];

    protected $hidden = ['attendee_pw', 'moderator_pw'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'started_at' => 'datetime', 'ended_at' => 'datetime', 'recurrence' => 'array', 'settings' => 'array',
            'attendee_pw' => 'encrypted', 'moderator_pw' => 'encrypted', 'duration_minutes' => 'integer'];
    }

    public const DEFAULT_SETTINGS = [
        'record' => false, 'auto_record' => false, 'waiting_room' => false, 'mute_on_start' => true, 'max_participants' => null,
        'lock_cameras' => false, 'lock_microphones' => false, 'lock_public_chat' => false, 'lock_private_chat' => false, 'hide_user_list' => false,
        'webcams_only_for_moderator' => false, 'join_before_host' => false, 'early_join_minutes' => 15, 'recording_visibility' => 'attendees',
        'welcome' => null,
        // in the room
        'layout' => 'SMART_LAYOUT', 'disabled_features' => [], 'listen_only' => false, 'skip_audio_check' => false,
        // a viva, practical or research defence held in this room: examiners score from the meeting page
        'exam_id' => null,
        // uploaded slides, preloaded in the room (managed by the slides routes, not the form)
        'slides' => [],
        // where the room runs: auto = the meeting server when one is connected, otherwise the built-in room;
        // builtin = always the built-in room (browsers connect directly; small meetings); bbb = always the meeting server
        'engine' => 'auto',
    ];

    public const ENGINES = ['auto', 'builtin', 'bbb'];

    public const LAYOUTS = ['SMART_LAYOUT', 'PRESENTATION_FOCUS', 'VIDEO_FOCUS', 'CUSTOM_LAYOUT'];

    /** BigBlueButton features a host may switch off for a meeting (BBB "disabledFeatures"). */
    public const FEATURES = [
        'breakoutRooms' => 'Breakout rooms', 'polls' => 'Polls', 'sharedNotes' => 'Shared notes', 'chat' => 'Chat', 'screenshare' => 'Screen sharing',
        'externalVideos' => 'Sharing external videos', 'learningDashboard' => 'Learning dashboard', 'captions' => 'Captions', 'virtualBackgrounds' => 'Virtual backgrounds',
        'presentation' => 'Presentation area', 'downloadPresentationWithAnnotations' => 'Downloading slides',
    ];

    public function setting(string $key): mixed
    {
        return ($this->settings ?? [])[$key] ?? self::DEFAULT_SETTINGS[$key] ?? null;
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function endsAt(): \Carbon\CarbonInterface
    {
        return $this->starts_at->copy()->addMinutes($this->duration_minutes);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(ConferenceServer::class, 'conference_server_id');
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class);
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(MeetingAttendance::class);
    }

    public function recordings(): HasMany
    {
        return $this->hasMany(MeetingRecording::class);
    }
}
