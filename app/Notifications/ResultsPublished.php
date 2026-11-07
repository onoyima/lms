<?php

namespace App\Notifications;

use App\Models\Exam;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In-platform notification (and e-mail when mail is configured) when results are released (Target 186, 201). */
class ResultsPublished extends Notification
{
    use Queueable;

    public function __construct(public Exam $exam) {}

    public function via(object $notifiable): array
    {
        return \App\Support\NotificationCategories::filter($notifiable, 'results', config('mail.default') && config('mail.default') !== 'log' && config('mail.default') !== 'array' ? ['database', 'mail'] : ['database']);
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => 'Result published', 'category' => 'results', 'body' => "Your result for {$this->exam->title} is available.", 'exam_id' => $this->exam->id];
    }

    public function toMail(object $notifiable): \Illuminate\Notifications\Messages\MailMessage
    {
        return (new \Illuminate\Notifications\Messages\MailMessage)
            ->subject('Result published: '.$this->exam->title)
            ->line("Your result for {$this->exam->title} is now available.")
            ->action('View result', url('/my/results'));
    }
}
