<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A one-time sign-in code by email (sent at once, not queued: it is needed now). */
class LoginCode extends Notification
{
    public function __construct(public string $code, public int $minutes) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Your sign-in code: '.$this->code)
            ->line('Your one-time sign-in code is:')
            ->line('**'.$this->code.'**')
            ->line("It works once, for {$this->minutes} minutes. If you did not ask for it, ignore this email; nobody can sign in without the code.");
    }
}
