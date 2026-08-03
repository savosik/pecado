<?php

namespace App\Notifications\Crm;

use App\Models\CrmTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Напоминание о сроке: завтра истекает или уже просрочено.
 *
 * Одно письмо на оба случая — они отличаются только формулировкой, а два шаблона
 * ради этого пришлось бы править парой при каждом изменении текста.
 */
class TaskDueSoonNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public CrmTask $task, public bool $overdue = false) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->task->loadMissing(['author:id,name', 'client:id,name']);

        return (new MailMessage)
            ->subject($this->overdue
                ? 'Задача просрочена — Pecado.ru'
                : 'Завтра истекает срок задачи — Pecado.ru')
            ->markdown('mail.crm.task-due', [
                'task' => $this->task,
                'overdue' => $this->overdue,
                'authorName' => $this->task->author->name,
                'clientName' => $this->task->client?->name,
                'dueLabel' => $this->task->due_at?->format('d.m.Y H:i'),
                'taskUrl' => url(route('crm.tasks.index', ['task' => $this->task->getKey()], false)),
            ]);
    }
}
