<?php

namespace App\Support;

/**
 * Institution-wide policy settings with safe defaults (LMS 4, 176, 202, 207, 213; Target 105, 207).
 * Stored in tenants.settings['policy']; read through here so every rule has one source.
 */
class Policy
{
    public const DEFAULTS = [
        'grading_scale' => ['A' => 70, 'B' => 60, 'C' => 50, 'D' => 45, 'E' => 40, 'F' => 0],
        'grade_points' => ['A' => 5, 'B' => 4, 'C' => 3, 'D' => 2, 'E' => 1, 'F' => 0],
        'pass_grade_min' => 'E',
        // other grading scales an exam can use instead (e.g. postgraduate, pass/fail, percentage bands):
        // name => {grades: {A: 70, …}, points: {A: 5, …}, pass: "C"}
        'grading_scales' => [],
        'otp_login' => true,                   // sign in with a one-time code by email or text message
        'email_domains' => [],                 // e.g. ["example.edu.ng"]; empty = any
        'severity_overrides' => [],            // event type => 1..5
        'faculty_exam_defaults' => [],         // faculty id => proctoring settings
        'exam_type_defaults' => [],            // cbt|essay|mixed|practical|viva => proctoring settings
        'min_attendance_pct' => null,          // e.g. 75: below this a student is not eligible for the exam
        'password_min' => 12,
        'password_mixed_case' => true,
        'password_numbers' => true,
        'password_symbols' => false,
        'mfa_required_roles' => ['institution_admin', 'ict_admin', 'exam_officer'],
        'export_approval_rows' => 500,         // personal-data exports above this need a second person
        'max_carryover_units' => 6,
        'resit_max_grade' => null,             // e.g. "C": resit results are capped at this grade's lowest mark
        'max_exams_per_day' => 2,              // timetable generator limit per student
        'student_messaging' => false,          // students may message classmates (they can always message their lecturers)
        'access_review_days' => 90,
        // who proctors call during an exam: [{role, name, phone, email, hours}] (exams office procedure)
        'incident_contacts' => [],
        'exam_day_notice' => '',               // shown to candidates before an exam starts
        'exam_paper_approval' => false,
        'recording_mode' => 'progressive',   // how exams record camera and screen unless an exam chooses otherwise
        'result_change_approval' => true,      // a changed score or corrected answer needs a second officer's approval        // papers need a second person's approval before use (off: the setter publishes)
        'auto_rollover' => false,              // close ended semesters and start the next one by themselves (LMS 172)
        'auto_rollover_copy_content' => true,  // and copy last session's course content into the new courses
    ];

    /** Settings a campus can set for itself; everything else is institution-wide (Target 99). */
    public const CAMPUS_KEYS = ['incident_contacts', 'exam_day_notice', 'max_exams_per_day', 'min_attendance_pct'];

    public static function all(): array
    {
        return array_replace(self::DEFAULTS, (array) data_get(tenant()?->settings, 'policy', []));
    }

    /** The institution's policy with one campus's own settings on top (missing campus = institution-wide). */
    public static function forCampus(?string $campus): array
    {
        $all = self::all();
        if (! $campus) {
            return $all;
        }
        $own = (array) data_get(tenant()?->settings, 'campus_policy.'.self::campusKey($campus), []);

        return array_replace($all, array_intersect_key(array_filter($own, fn ($v) => $v !== null && $v !== '' && $v !== []), array_flip(self::CAMPUS_KEYS)));
    }

    public static function campusKey(string $campus): string
    {
        return substr(preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($campus))), 0, 40);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    /** The scale an exam uses: one of the institution's other scales by name, or the main one. */
    public static function scale(?string $name = null): array
    {
        $other = $name ? (self::get('grading_scales')[$name] ?? null) : null;

        return $other && ! empty($other['grades'])
            ? ['grades' => array_map('floatval', (array) $other['grades']), 'points' => array_map('floatval', (array) ($other['points'] ?? [])), 'pass' => (string) ($other['pass'] ?? array_key_first((array) $other['grades']))]
            : ['grades' => self::get('grading_scale'), 'points' => self::get('grade_points'), 'pass' => (string) self::get('pass_grade_min')];
    }

    public static function grade(float $percent, ?string $scaleName = null): string
    {
        $scale = self::scale($scaleName)['grades'];
        arsort($scale);
        foreach ($scale as $grade => $min) {
            if ($percent >= $min) {
                return (string) $grade;
            }
        }

        return (string) array_key_last($scale);
    }

    public static function gradePoint(?string $grade): float
    {
        return (float) (self::get('grade_points')[$grade] ?? 0);
    }

    public static function emailAllowed(string $email): bool
    {
        $domains = array_filter(array_map('strtolower', (array) self::get('email_domains')));
        if (! $domains) {
            return true;
        }
        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
        foreach ($domains as $d) {
            if ($domain === $d || str_ends_with($domain, '.'.$d)) {
                return true;
            }
        }

        return false;
    }
}
