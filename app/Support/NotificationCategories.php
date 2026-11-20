<?php

namespace App\Support;

/**
 * Kinds of notification. People choose, per kind, whether they get it in the app and by email —
 * except security alerts, which always arrive both ways.
 */
class NotificationCategories
{
    /** key => [label, description, mandatory] */
    public const ALL = [
        'course' => ['Courses', 'New material, deadlines, marks and replies in your courses', false],
        'announcements' => ['Announcements', 'Announcements from lecturers, departments and the institution', false],
        'exams' => ['Exams and proctoring', 'Exam schedules, proctoring shifts and alerts', false],
        'results' => ['Results', 'Published exam and course results', false],
        'messages' => ['Messages', 'Direct messages from other people', false],
        'requests' => ['Requests', 'Support tickets and exception requests', false],
        'billing' => ['Billing', 'Invoices, payments, renewal reminders and receipts', false],
        'system' => ['System', 'Maintenance, platform notices and service alerts', false],
        'security' => ['Security alerts', 'Sign-ins, password and access changes, support sign-ins', true],
    ];

    public static function mandatory(string $category): bool
    {
        return (bool) (self::ALL[$category][2] ?? false);
    }

    /** Kinds that come by text message unless the person turns them off (texts cost money; the rest are opt-in). */
    public const SMS_BY_DEFAULT = ['exams', 'results'];

    /** Does this person want this kind of notice on this channel ('app', 'mail' or 'sms')? */
    public static function wants(object $notifiable, string $category, string $channel): bool
    {
        if ($channel === 'sms' && (empty($notifiable->phone) || ! \App\Services\Sms\SmsSender::enabled())) {
            return false;
        }
        if (self::mandatory($category)) {
            return true;
        }
        $prefs = (array) ($notifiable->notification_preferences ?? []);

        return (bool) ($prefs[$category][$channel] ?? ($channel === 'sms' ? in_array($category, self::SMS_BY_DEFAULT, true) : true));
    }

    /** @param list<string> $channels */
    public static function filter(object $notifiable, string $category, array $channels): array
    {
        return array_values(array_filter($channels, function ($c) use ($notifiable, $category) {
            return match ($c) {
                'database', 'broadcast' => self::wants($notifiable, $category, 'app'),
                'mail' => self::wants($notifiable, $category, 'mail'),
                \App\Notifications\Channels\SmsChannel::class => self::wants($notifiable, $category, 'sms'),
                default => true, // the institution's own webhook copy is the institution's choice
            };
        }));
    }
}
