<?php

namespace App\Notifications\Crm;

use App\Models\CrmTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Push-напоминание о задаче — догоняет менеджера и с закрытой вкладкой CRM.
 *
 * Гейт (флаг CRM_PUSH_ENABLED + VAPID-ключи + отметка в reminder-логе) стоит
 * на вызывающей стороне: уведомление не решает, слать ли себя.
 */
class TaskPushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public CrmTask $task,
        public string $title,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, object $notification): WebPushMessage
    {
        $body = $this->task->title
            .($this->task->due_at !== null ? ' · '.$this->task->due_at->format('d.m H:i') : '');

        return (new WebPushMessage)
            ->title($this->title)
            ->body($body)
            // Клик ведёт на карточку задачи — обрабатывает sw.js.
            ->data(['url' => url('/crm/tasks?task='.$this->task->getKey())])
            ->tag('crm-task-'.$this->task->getKey())
            ->options(['TTL' => 3600]);
    }
}
