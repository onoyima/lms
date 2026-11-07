<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * General course notification: new material, deadlines, marks, announcements,
 * timetable changes (LMS 34, 50, 180-188). Stored in the platform (with a delivery log
 * in the notifications table) and e-mailed when mail is configured. Queued, so a
 * class of 2,000 does not slow the lecturer's request down.
 */
class LmsNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $title, public string $body, public ?string $url = null, public bool $urgent = false, public string $category = 'course') {}

    public function via(object $notifiable): array
    {
        $mail = config('mail.default') && ! in_array(config('mail.default'), ['log', 'array'], true);
        $channels = $mail ? ['database', 'mail'] : ['database'];
        // a copy to the institution's own notification service, when connected (LMS 220)
        if (data_get(tenant()?->settings, 'integrations.notify.enabled')) {
            $channels[] = \App\Notifications\Channels\InstitutionWebhookChannel::class;
        }

        return \App\Support\NotificationCategories::filter($notifiable, $this->category, $channels);
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'body' => $this->body, 'url' => $this->url, 'urgent' => $this->urgent, 'category' => $this->category];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // branded with the person's institution (name in the subject and signature, its address in the footer)
        $t = \App\Models\Tenant::cached((int) ($notifiable->tenant_id ?? 0));
        $b = (array) ($t?->settings['branding'] ?? []);
        $mail = (new MailMessage)->subject(($t ? '['.($t->short_name ?: $t->name).'] ' : '').$this->title)->line($this->body)
            ->salutation($t ? $t->name.(! empty($b['official_email']) ? "\n".$b['official_email'] : '') : \App\Support\Platform\PlatformSettings::brand()['name']);

        return $this->url ? $mail->action('Open', url($this->url)) : $mail;
    }
}
