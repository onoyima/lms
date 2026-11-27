<?php

namespace App\Support\Exams;

/**
 * Default exam settings and proctoring policy. Stored per exam as JSON and merged over
 * these defaults, so new options never break old exams. Legacy portal flags map 1:1:
 *   shuffle_objective -> shuffle_questions, shuffle_options, student_can_view_score -> show_score,
 *   proctor_fullscreen -> require_fullscreen, proctor_tab_lock -> detect_tab_switch,
 *   proctor_kb_lock -> block_shortcuts, proctor_webcam -> webcam, proctor_live_video -> live_video,
 *   proctor_auto_submit + "5 strikes" -> violation_threshold / violation_action.
 */
class ExamSettings
{
    public const SETTINGS = [
        'shuffle_questions' => true,
        'shuffle_options' => true,
        'show_score' => false,         // candidate sees the score right after submitting (objective only)
        'show_review' => false,        // candidate may review answers after results are published
        'allow_navigation' => true,    // move freely between questions
        'allow_flagging' => true,
        'calculator' => false,
        'autosave_seconds' => 10,
        'heartbeat_seconds' => 15,
        'negative_marking' => 0,       // marks deducted per wrong objective answer
        'partial_credit' => false,     // multiple-answer questions: share of correct choices instead of all-or-nothing
        'mtf_scoring' => 'per_statement',
        'grading_scale' => '',            // '' = the institution's main scale, else the name of one of its other scales // multiple true/false: per_statement (each statement its share) or all_or_nothing
        'require_check_in' => false,   // no longer used: students never wait for an invigilator check-in to start
        // Emergency exam: candidates see only this exam after signing in and start with one click. No
        // pre-exam checks, monitoring notice, camera, microphone, screen or approved-computer
        // requirements; the exam is timed by the invigilator in the room, with the on-screen timer still running.
        'emergency' => false,
        // Starting late: "sitting" = the sitting's own late-entry minutes; "any_time" = may start until the
        // sitting closes (even 30+ minutes in); "cutoff" = no new starts once late_start_minutes have passed.
        'late_start' => 'sitting',
        'late_start_minutes' => 30,
        // Connection and power safety. A candidate who loses the connection keeps writing; answers stay on
        // their computer and are sent when it returns. Time is given back only when the computer itself
        // went off (power cut, crash, flat battery), up to this many minutes in the whole exam (0 = never).
        'power_cut_minutes' => 30,
        // How long after time is up the server waits for a computer it lost contact with before closing
        // the attempt, and how long answers written in time may still arrive after closing.
        'offline_sync_minutes' => 120,
    ];

    public const PROCTORING = [
        'require_consent' => true,         // monitoring notice must be accepted (Target 175)
        'require_fullscreen' => false,
        'detect_tab_switch' => true,
        'detect_focus_loss' => true,
        'block_copy_paste' => true,
        'block_shortcuts' => false,
        'detect_multiple_displays' => true,
        'webcam' => false,                 // snapshots
        'snapshot_seconds' => 60,
        'face_detection' => false,         // in-browser face presence / multiple faces / gaze
        'microphone' => false,
        'screen_share' => false,
        'second_camera' => false,          // phone or USB camera (Target 111-124)
        'live_video' => false,             // proctors can watch live (media server)
        'record_video' => false,
        'record_screen' => false,
        'identity_check' => 'none',        // none, photo_match, proctor_review (Target 1, 101, 172)
        'identity_recheck_minutes' => 0,   // repeat the face check during the exam (0 = only at entry)
        'gaze_tracking' => false,          // head turned away from the screen for a while (Target 9)
        'violation_threshold' => 5,        // legacy: 5 strikes
        'violation_action' => 'lock',      // warn, lock, submit
        'device_whitelist' => false,       // hall mode: only approved computers (Target 163)
        'ip_approval' => false,            // hall mode: only computers approved by IP address on the exam network page
        'single_session' => true,          // a new login moves the exam and stops the old window (Target 27, 75)
        'low_bandwidth' => false,          // snapshots instead of video (Target 182)
        'close_banned_apps' => false,      // the desktop app closes remote-control and screen-capture apps (Target 120)
        'pre_checks' => [],                // checks that must pass before the exam can start (see PRE_CHECKS); empty = none
        // how camera and screen recordings are made (only when the exam records at all):
        //   institution = the institution's choice in Policy; stream = live to the media server, recorded as it goes;
        //   progressive = recorded in the browser and uploaded as it goes; on_submit = recorded in the browser and
        //   uploaded after the candidate submits; triggered = short clips only when something happens
        'recording_mode' => 'institution',
    ];

    /**
     * Checks a candidate's computer and room must pass before the exam starts. The exam's setter picks
     * any of them (or all, or none). Each must succeed on the candidate's screen; the server refuses to
     * start without them, and the results are kept with the attempt.
     */
    public const PRE_CHECKS = [
        'browser' => ['Browser', 'A supported, up-to-date browser on a secure (https) connection'],
        'network' => ['Internet connection', 'The exam server answers quickly enough'],
        'device' => ['Computer', 'A laptop or desktop computer, not a phone'],
        'power' => ['Power', 'The laptop is charging, or its battery is above half'],
        'screen' => ['Screen', 'One display only, large enough to read the paper'],
        'fullscreen' => ['Full screen', 'The exam can open in full screen'],
        'keyboard' => ['Keyboard', 'The candidate types a short phrase'],
        'camera' => ['Camera', 'The camera works and the picture is not dark or covered'],
        'face' => ['Face', 'One face is clearly visible and well lit'],
        'microphone' => ['Microphone', 'The microphone picks up the candidate\'s voice'],
        'speakers' => ['Sound', 'The candidate hears a test sound (for listening questions)'],
        'screen_share' => ['Screen sharing', 'The whole screen can be shared for monitoring'],
        'secure_app' => ['Secure exam app', 'Opened in the desktop exam app or Safe Exam Browser'],
        'room_scan' => ['Room scan', 'A short video turning the camera around the room and desk, kept as evidence'],
    ];

    /** Checks that need the camera, microphone or screen (an approved hardware exception can waive them). */
    public const PRE_CHECK_NEEDS = [
        'camera' => 'webcam', 'face' => 'face_detection', 'room_scan' => 'webcam', 'microphone' => 'microphone', 'screen_share' => 'screen_share', 'fullscreen' => 'require_fullscreen', 'screen' => 'detect_multiple_displays',
    ];

    /** The checks this candidate must pass (an approved hardware exception removes the ones it covers). */
    public static function preChecksFor(array $policy, array $relaxed = []): array
    {
        $list = array_values(array_intersect(array_keys(self::PRE_CHECKS), (array) ($policy['pre_checks'] ?? [])));

        return array_values(array_filter($list, fn ($c) => ! in_array(self::PRE_CHECK_NEEDS[$c] ?? '', $relaxed, true)));
    }

    /** Severity weights used for risk scoring (Target 68, 105, 151). */
    public const EVENT_WEIGHTS = [
        'tab_hidden' => 8, 'focus_lost' => 4, 'fullscreen_exit' => 8, 'copy_blocked' => 3, 'paste_blocked' => 6,
        'shortcut_blocked' => 2, 'right_click' => 1, 'multiple_displays' => 15, 'display_change' => 10,
        'face_absent' => 10, 'multiple_faces' => 20, 'gaze_away' => 3, 'camera_off' => 15, 'camera_changed' => 12,
        'camera_obstructed' => 15, 'microphone_off' => 6, 'noise_detected' => 3, 'screen_share_stopped' => 15,
        'network_offline' => 1, 'devtools_open' => 20, 'vm_detected' => 25, 'remote_desktop' => 30,
        'usb_device' => 10, 'navigation_attempt' => 5, 'identity_mismatch' => 40, 'second_camera_lost' => 10,
        'wireless_display' => 25, 'external_display' => 10, 'screen_capture_app' => 15, 'camera_virtual' => 25,
        'identity_unverified' => 10, 'media_disconnected' => 3, 'media_poor' => 0, 'recording_failed' => 0, 'second_camera_linked' => 0, 'app_closed' => 5,
        'screen_left' => 8, 'device_off' => 0, 'device_asleep' => 0, 'late_sync' => 0, 'network_online' => 0,
    ];

    /** Events that count towards the violation threshold. */
    public const VIOLATIONS = [
        'tab_hidden', 'screen_left', 'fullscreen_exit', 'paste_blocked', 'multiple_displays', 'camera_off',
        'screen_share_stopped', 'devtools_open', 'vm_detected', 'remote_desktop', 'wireless_display', 'camera_virtual',
    ];

    /**
     * Camera and microphone judgements made by AI (faces, gaze, noise, a covered lens, a face that does not
     * match). They are confirmed over several frames on the candidate's computer, then put in front of a person
     * with the picture; they never count as a warning strike, so they can never lock or submit an exam by
     * themselves. A person decides.
     */
    public const AI_EVENTS = ['face_absent', 'multiple_faces', 'gaze_away', 'noise_detected', 'camera_obstructed', 'identity_mismatch'];

    public static function settings(?array $stored): array
    {
        $out = array_replace(self::SETTINGS, array_intersect_key($stored ?? [], self::SETTINGS));
        $out['late_start'] = in_array($out['late_start'], ['sitting', 'any_time', 'cutoff'], true) ? $out['late_start'] : 'sitting';
        $out['late_start_minutes'] = max(1, min(600, (int) $out['late_start_minutes']));
        $out['grading_scale'] = is_string($out['grading_scale']) ? mb_substr($out['grading_scale'], 0, 60) : '';
        $out['mtf_scoring'] = in_array($out['mtf_scoring'], ['per_statement', 'all_or_nothing'], true) ? $out['mtf_scoring'] : 'per_statement';
        $out['power_cut_minutes'] = max(0, min(240, (int) $out['power_cut_minutes']));
        $out['offline_sync_minutes'] = max(10, min(4320, (int) $out['offline_sync_minutes']));
        $out['emergency'] = (bool) $out['emergency'];

        return $out;
    }

    /** The last moment a candidate may start (not resume) this exam in this sitting. */
    public static function lastStart(\App\Models\Exam $exam, \App\Models\ExamSession $session): \Carbon\CarbonInterface
    {
        $s = self::settings($exam->settings);
        $last = match ($s['late_start']) {
            'any_time' => $session->ends_at->copy(),
            'cutoff' => $session->starts_at->copy()->addMinutes($s['late_start_minutes']),
            default => $session->late_entry_minutes > 0 ? $session->starts_at->copy()->addMinutes($session->late_entry_minutes) : $session->ends_at->copy(),
        };

        return $last->min($session->ends_at);
    }

    /** Starting proctoring rules for a new exam: platform defaults, then the exam type's, then the faculty's (policy). */
    public static function defaultsFor(string $type, ?int $courseId): array
    {
        $policy = \App\Support\Policy::all();
        $faculty = $courseId ? \Illuminate\Support\Facades\DB::table('courses')->join('departments', 'departments.id', '=', 'courses.department_id')
            ->where('courses.id', $courseId)->value('departments.faculty_id') : null;

        return self::proctoring(array_replace(self::PROCTORING, (array) ($policy['exam_type_defaults'][$type] ?? []), (array) ($faculty ? ($policy['faculty_exam_defaults'][$faculty] ?? []) : [])));
    }

    /** Allowed values for the text options; anything else falls back to the default. */
    public const CHOICES = [
        'identity_check' => ['none', 'photo_match', 'proctor_review'],
        'violation_action' => ['warn', 'lock', 'submit'],
        'recording_mode' => ['institution', 'stream', 'progressive', 'on_submit', 'triggered'],
    ];

    public const RECORDING_MODES = ['stream', 'progressive', 'on_submit', 'triggered'];

    /** The recording mode that applies: the exam's own, or the institution's default. */
    public static function recordingMode(array $p): string
    {
        $mode = $p['recording_mode'] ?? 'institution';
        if ($mode === 'institution' || ! in_array($mode, self::RECORDING_MODES, true)) {
            $mode = (string) \App\Support\Policy::get('recording_mode');
        }

        return in_array($mode, self::RECORDING_MODES, true) ? $mode : 'progressive';
    }

    /** Proctoring checks an approved hardware exception can switch off for one candidate (Target 141). */
    public const RELAXABLE = ['webcam', 'face_detection', 'microphone', 'screen_share', 'second_camera', 'live_video', 'record_video', 'record_screen',
        'gaze_tracking', 'require_fullscreen', 'detect_multiple_displays', 'device_whitelist', 'identity_check', 'close_banned_apps'];

    /** The exam's proctoring rules for one candidate, after any approved hardware exception. */
    public static function forCandidate(?array $stored, int $userId, int $examId): array
    {
        $p = self::proctoring($stored);

        if (self::isEmergency($examId)) {
            return self::emergencyPolicy($p);
        }

        $relaxed = [];
        foreach (\App\Models\HardwareException::activeFor($userId, $examId) as $ex) {
            foreach (array_intersect((array) $ex->relax, self::RELAXABLE) as $key) {
                $p[$key] = $key === 'identity_check' ? 'proctor_review' : false;
                $relaxed[] = $key;
            }
        }
        $p['pre_checks'] = self::preChecksFor($p, $relaxed);
        // the monitoring notice is only shown when the exam actually uses the camera, microphone or screen;
        // an exam supervised in person, with none of these, starts without it
        $p['require_consent'] = $p['require_consent'] && self::watchesCandidate($p);
        $p['recording_mode'] = self::recordingMode($p);
        // live watching needs the live-streaming mode; the other modes send no live video at all
        $p['live_video'] = $p['live_video'] && $p['recording_mode'] === 'stream';

        return $p;
    }

    /** Is this an emergency exam (one click to start, nothing to check)? Read once per request. */
    public static function isEmergency(int $examId): bool
    {
        return once(fn () => (bool) data_get(\App\Models\Exam::whereKey($examId)->value('settings'), 'emergency', false));
    }

    /** An emergency exam's rules: nothing to pass before starting and nothing watching; answers still protected. */
    public static function emergencyPolicy(array $p): array
    {
        foreach (['webcam', 'face_detection', 'microphone', 'screen_share', 'second_camera', 'live_video', 'record_video', 'record_screen', 'gaze_tracking',
            'require_consent', 'require_fullscreen', 'detect_tab_switch', 'detect_focus_loss', 'detect_multiple_displays', 'device_whitelist', 'ip_approval', 'single_session'] as $k) {
            if (array_key_exists($k, $p)) {
                $p[$k] = false;
            }
        }
        $p['identity_check'] = 'none';
        $p['pre_checks'] = [];
        $p['recording_mode'] = self::recordingMode($p); // nothing is recorded: camera, microphone and screen are off
        $p['emergency'] = true;

        return $p;
    }

    /** Does this exam use the camera, microphone or screen at all? */
    public static function watchesCandidate(array $p): bool
    {
        foreach (['webcam', 'face_detection', 'microphone', 'screen_share', 'second_camera', 'live_video', 'record_video', 'record_screen', 'gaze_tracking'] as $k) {
            if (! empty($p[$k])) {
                return true;
            }
        }

        return ($p['identity_check'] ?? 'none') !== 'none';
    }

    /** Known keys only, each cast to the type of its default and kept within sane limits. */
    public static function proctoring(?array $stored): array
    {
        $out = self::PROCTORING;
        foreach (array_intersect_key($stored ?? [], self::PROCTORING) as $k => $v) {
            $default = self::PROCTORING[$k];
            $out[$k] = match (true) {
                is_array($default) => array_values(array_intersect(array_keys(self::PRE_CHECKS), array_map('strval', (array) $v))),
                is_bool($default) => filter_var($v, FILTER_VALIDATE_BOOLEAN),
                is_int($default) => max(0, min(100000, (int) $v)),
                default => in_array($v, self::CHOICES[$k] ?? [], true) ? $v : $default,
            };
        }
        $out['snapshot_seconds'] = max(10, $out['snapshot_seconds']);
        $out['violation_threshold'] = max(1, $out['violation_threshold']);

        return $out;
    }

    public static function severity(string $type): int
    {
        $override = \App\Support\Policy::get('severity_overrides')[$type] ?? null;
        if ($override) {
            return max(1, min(5, (int) $override));
        }
        $w = self::EVENT_WEIGHTS[$type] ?? 1;

        return match (true) {
            $w >= 25 => 5, $w >= 15 => 4, $w >= 8 => 3, $w >= 3 => 2, default => 1,
        };
    }
}
