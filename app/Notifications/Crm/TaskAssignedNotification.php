<?php

namespace App\Notifications\Crm;

use App\Models\CrmTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * «Вам поручена задача» — исполнителю в момент назначения.
 *
 * Гейт по фича-флагу стоит на вызывающей стороне (`CrmTaskService`), как и у остальных
 * писем проекта: уведомление не должно решать, слать ли себя.
 */
class TaskAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public CrmTask $task) {}

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
            ->subject('Вам поручена задача — Pecado.ru')
            ->markdown('mail.crm.task-assigned', [
                'task' => $this->task,
                'authorName' => $this->task->author->name,
                'clientName' => $this->task->client?->name,
                'dueLabel' => $this->task->due_at?->format('d.m.Y H:i'),
                'taskUrl' => url(route('crm.tasks.index', ['task' => $this->task->getKey()], false)),
            ]);
    }
}
