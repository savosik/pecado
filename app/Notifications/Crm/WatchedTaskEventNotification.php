<?php

namespace App\Notifications\Crm;

use App\Models\CrmTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Событие по задаче на личном контроле: закрытие с исходом или перенос срока.
 *
 * Контролёр наблюдает — само событие для него и есть продукт: он не участник
 * работы, и лента задачи ему на глаза не попадётся.
 *
 * Гейт по фича-флагу стоит на вызывающей стороне (`CrmTaskService`).
 */
class WatchedTaskEventNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [30, 120, 300];

    /**
     * @param  'closed'|'postponed'  $event
     */
    public function __construct(
        public CrmTask $task,
        public string $event,
        public ?string $detail = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->task->loadMissing(['assignee:id,name']);

        $subject = $this->event === 'closed'
            ? 'Задача на контроле закрыта — Pecado.ru'
            : 'Задача на контроле перенесена — Pecado.ru';

        return (new MailMessage)
            ->subject($subject)
            ->markdown('mail.crm.task-watched-event', [
                'task' => $this->task,
                'event' => $this->event,
                'detail' => $this->detail,
                'assigneeName' => $this->task->assignee->name,
                'outcomeLabel' => $this->task->outcome?->label(),
                'dueLabel' => $this->task->due_at?->format('d.m.Y H:i'),
                'taskUrl' => url(route('crm.tasks.index', ['task' => $this->task->getKey()], false)),
            ]);
    }
}
