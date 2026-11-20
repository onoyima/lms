<?php

namespace App\Notifications\Channels;

use App\Services\Sms\SmsSender;
use Illuminate\Notifications\Notification;

/** Sends a notification's toSms() text to the person's mobile number, when SMS is set up. */
class SmsChannel
{
    public function __construct(private SmsSender $sms) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toSms') || empty($notifiable->phone)) {
            return;
        }
        $this->sms->send((string) $notifiable->phone, (string) $notification->toSms($notifiable), class_basename($notification), $notifiable->id ?? null);
    }
}
