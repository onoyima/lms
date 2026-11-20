<?php

namespace App\Services\Exams;

use App\Models\Question;

/**
 * Ready-made monitoring levels for a paper (Light, Standard, Strict) and the size of the question
 * bank for a course, used when an exam and its papers are set up.
 */
class ExamPresets
{
    public const PROTECTION = ['light', 'standard', 'strict'];

    /** Proctoring rules for each protection level, laid over the exam type's defaults. */
    public static function protection(string $level): array
    {
        $base = ['require_consent' => true, 'detect_tab_switch' => true, 'detect_focus_loss' => true, 'block_copy_paste' => true,
            'detect_multiple_displays' => true, 'webcam' => false, 'face_detection' => false, 'microphone' => false, 'screen_share' => false,
            'record_video' => false, 'record_screen' => false, 'require_fullscreen' => false, 'identity_check' => 'none',
            'violation_action' => 'warn', 'violation_threshold' => 5, 'device_whitelist' => false, 'ip_approval' => false];

        $checks = ['light' => ['browser', 'network', 'keyboard'], 'standard' => ['browser', 'network', 'device', 'screen', 'keyboard', 'camera', 'face'],
            'strict' => ['browser', 'network', 'device', 'power', 'screen', 'fullscreen', 'keyboard', 'camera', 'face', 'microphone', 'screen_share', 'room_scan']];
        $base['pre_checks'] = $checks[$level] ?? $checks['standard'];

        return match ($level) {
            'light' => $base,
            'strict' => array_replace($base, ['webcam' => true, 'snapshot_seconds' => 30, 'face_detection' => true, 'microphone' => true,
                'record_video' => true, 'screen_share' => true, 'record_screen' => true, 'require_fullscreen' => true,
                'identity_check' => 'proctor_review', 'violation_action' => 'lock']),
            default => array_replace($base, ['webcam' => true, 'snapshot_seconds' => 60, 'face_detection' => true]),
        };
    }

    /** Approved questions available for a draw (drafts too for practice). */
    public function poolSize(?int $courseId, ?string $topic, string $category): int
    {
        return Question::query()
            ->whereIn('status', $category === 'practice' ? ['approved', 'draft', 'in_review'] : ['approved'])
            ->when($courseId, fn ($q) => $q->where('course_id', $courseId))
            ->when($topic, fn ($q) => $q->where('topic', $topic))
            ->count();
    }
}
