<?php

namespace App\Notifications\UserQuestions;

use App\Models\UserQuestion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QuestionAnsweredNotification extends Notification implements ShouldQueue
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
        $name = trim((string) ($this->question->name ?? '')) ?: 'друг';

        return (new MailMessage)
            ->subject('Ответ на ваш вопрос — Pecado.ru')
            ->markdown('mail.user-questions.answered', [
                'question' => $this->question,
                'name' => $name,
                'isAuthenticated' => $this->question->user_id !== null,
                'cabinetUrl' => $this->question->user_id
                    ? url(route('cabinet.questions.show', $this->question, false))
                    : null,
            ]);
    }
}
