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
        'require_check_in' => true,    // hall sittings: invigilator checks the candidate in first (Target 96)
        // Starting late: "sitting" = the sitting's own late-entry minutes; "any_time" = may start until the
        // sitting closes (even 30+ minutes in); "cutoff" = no new starts once late_start_minutes have passed.
        'late_start' => 'sitting',
        'late_start_minutes' => 30,
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
    ];

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
    ];

    /** Events that count towards the violation threshold. */
    public const VIOLATIONS = [
        'tab_hidden', 'fullscreen_exit', 'paste_blocked', 'multiple_displays', 'multiple_faces', 'camera_off',
        'camera_obstructed', 'screen_share_stopped', 'devtools_open', 'vm_detected', 'remote_desktop', 'identity_mismatch', 'wireless_display', 'camera_virtual',
    ];

    public static function settings(?array $stored): array
    {
        $out = array_replace(self::SETTINGS, array_intersect_key($stored ?? [], self::SETTINGS));
        $out['late_start'] = in_array($out['late_start'], ['sitting', 'any_time', 'cutoff'], true) ? $out['late_start'] : 'sitting';
        $out['late_start_minutes'] = max(1, min(600, (int) $out['late_start_minutes']));

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
    ];

    /** Proctoring checks an approved hardware exception can switch off for one candidate (Target 141). */
    public const RELAXABLE = ['webcam', 'face_detection', 'microphone', 'screen_share', 'second_camera', 'live_video', 'record_video', 'record_screen',
        'gaze_tracking', 'require_fullscreen', 'detect_multiple_displays', 'device_whitelist', 'identity_check', 'close_banned_apps'];

    /** The exam's proctoring rules for one candidate, after any approved hardware exception. */
    public static function forCandidate(?array $stored, int $userId, int $examId): array
    {
        $p = self::proctoring($stored);
        foreach (\App\Models\HardwareException::activeFor($userId, $examId) as $ex) {
            foreach (array_intersect((array) $ex->relax, self::RELAXABLE) as $key) {
                $p[$key] = $key === 'identity_check' ? 'proctor_review' : false;
            }
        }

        return $p;
    }

    /** Known keys only, each cast to the type of its default and kept within sane limits. */
    public static function proctoring(?array $stored): array
    {
        $out = self::PROCTORING;
        foreach (array_intersect_key($stored ?? [], self::PROCTORING) as $k => $v) {
            $default = self::PROCTORING[$k];
            $out[$k] = match (true) {
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
