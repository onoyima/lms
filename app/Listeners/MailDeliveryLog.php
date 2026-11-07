<?php

namespace App\Listeners;

use App\Models\MailLog;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Support\Str;

/**
 * Records what happened to every e-mail (LMS 188): handed to the mail server, failed, or bounced
 * later (reported by the mail service's webhook). Addresses are kept; message bodies are not.
 */
class MailDeliveryLog
{
    public function sending(MessageSending $event): void
    {
        try {
            $m = $event->message;
            $to = collect($m->getTo())->map(fn ($a) => $a->getAddress())->implode(', ');
            $log = MailLog::create(['tenant_id' => tenant_id(), 'to' => Str::limit($to, 250, ''), 'subject' => Str::limit((string) $m->getSubject(), 250, ''),
                'kind' => Str::limit((string) ($event->data['__laravel_notification'] ?? $event->data['__laravel_mailable'] ?? 'mail'), 120, ''), 'status' => 'sending']);
            $m->getHeaders()->addTextHeader('X-Platform-Mail', (string) $log->id);
        } catch (\Throwable) {
            // logging must never stop an e-mail
        }
    }

    public function sent(MessageSent $event): void
    {
        $id = $event->message->getHeaders()->get('X-Platform-Mail')?->getBodyAsString();
        if ($id && ctype_digit($id)) {
            MailLog::whereKey((int) $id)->update(['status' => 'sent', 'message_id' => Str::limit((string) $event->sent->getMessageId(), 250, '')]);
        }
    }

    public function failed(NotificationFailed $event): void
    {
        if ($event->channel !== 'mail') {
            return;
        }
        $to = method_exists($event->notifiable, 'routeNotificationFor') ? (string) $event->notifiable->routeNotificationFor('mail') : '';
        $log = MailLog::where('to', $to)->where('status', 'sending')->latest('id')->first();
        $error = Str::limit((string) data_get($event->data, 'exception')?->getMessage(), 480, '');
        $log ? $log->update(['status' => 'failed', 'error' => $error]) : MailLog::create(['tenant_id' => tenant_id(), 'to' => $to, 'kind' => get_class($event->notification), 'status' => 'failed', 'error' => $error]);
    }
}
