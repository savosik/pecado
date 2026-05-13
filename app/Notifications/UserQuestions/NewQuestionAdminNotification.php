<?php

namespace App\Notifications\UserQuestions;

use App\Models\UserQuestion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewQuestionAdminNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public UserQuestion $question) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Новый вопрос с сайта — Pecado.ru')
            ->markdown('mail.user-questions.manager-new', [
                'question' => $this->question,
                'adminUrl' => url(route('admin.user-questions.show', $this->question, false)),
                'hasAttachment' => $this->question->getFirstMedia('attachment') !== null,
            ]);
    }
}
