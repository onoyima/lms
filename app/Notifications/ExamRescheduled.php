<?php

namespace App\Notifications;

use App\Models\ExamSession;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Candidates are told when their sitting moves to another time or room (LMS item 183). */
class ExamRescheduled extends Notification
{
    use Queueable;

    public function __construct(public ExamSession $session, public string $was) {}

    public function via(object $notifiable): array
    {
        return \App\Support\NotificationCategories::filter($notifiable, 'exams', ['database', 'mail']);
    }

    private function now(): string
    {
        return $this->session->starts_at->format('D j M, H:i').($this->session->venue ? ' at '.$this->session->venue->name : ' (online)');
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => 'Exam time changed', 'category' => 'exams', 'exam_session_id' => $this->session->id,
            'body' => $this->session->exam->title.' is now '.$this->now().' (was '.$this->was.').'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Exam time changed: '.$this->session->exam->title)
            ->line($this->session->exam->title.' has moved.')->line('Now: '.$this->now())->line('Before: '.$this->was)
            ->action('My exams', url('/my/exams'));
    }
}
