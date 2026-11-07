<?php

namespace App\Notifications\Channels;

use App\Services\Integrations\Connectors;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

/**
 * Sends a signed copy of a notice to the institution's own notification service — an SMS
 * gateway, Microsoft Teams or Slack bridge, or the campus mobile app (LMS 220).
 * Body: {"event":"notice","institution","title","body","url","urgent","recipient":{"name","email","identifier"}}
 * Header: X-Platform-Signature = hmac_sha256(body, shared secret)
 */
class InstitutionWebhookChannel
{
    public function __construct(private Connectors $connectors) {}

    public function send(object $notifiable, Notification $notification): void
    {
        $c = $this->connectors->get('notify');
        if (! $c['enabled'] || ! $c['url'] || ! $c['secret'] || ! Connectors::safeUrl($c['url'])) {
            return;
        }
        $data = method_exists($notification, 'toArray') ? $notification->toArray($notifiable) : [];
        if ($c['urgent_only'] && empty($data['urgent'])) {
            return;
        }
        $body = json_encode(['event' => 'notice', 'institution' => tenant()?->slug, 'title' => $data['title'] ?? null, 'body' => $data['body'] ?? null,
            'url' => isset($data['url']) ? url($data['url']) : null, 'urgent' => (bool) ($data['urgent'] ?? false),
            'recipient' => ['name' => $notifiable->name ?? null, 'email' => $notifiable->email ?? null, 'identifier' => $notifiable->identifier ?? null]], JSON_UNESCAPED_SLASHES);
        Http::timeout(10)->withHeaders(['X-Platform-Signature' => Connectors::sign($body, $c['secret'])])->withBody($body, 'application/json')->post($c['url'])->throw();
    }
}
