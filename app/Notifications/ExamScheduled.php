<?php

namespace App\Notifications;

use App\Models\ExamSession;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Candidates are told when and where they sit (Target 85, 183, 201). */
class ExamScheduled extends Notification
{
    use Queueable;

    public function __construct(public ExamSession $session) {}

    public function via(object $notifiable): array
    {
        return \App\Support\NotificationCategories::filter($notifiable, 'exams', ['database', \App\Notifications\Channels\SmsChannel::class]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Exam scheduled', 'category' => 'exams',
            'body' => $this->session->exam->title.' — '.$this->session->starts_at->format('D j M, H:i').($this->session->venue ? ' at '.$this->session->venue->name : ' (online)'),
            'exam_session_id' => $this->session->id,
        ];
    }

    public function toSms(object $notifiable): string
    {
        $s = $this->session;

        return 'Exam: '.$s->exam->title.', '.$s->starts_at->format('D j M, H:i').($s->venue ? ', '.$s->venue->name : ', online').'.';
    }
}
