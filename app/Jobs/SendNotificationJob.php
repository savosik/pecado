<?php

namespace App\Jobs;

use App\Enums\Crm\EmailStatus;
use App\Models\CrmEmail;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Отправка уведомления после окна склейки.
 *
 * Окно нужно ровно за тем, чтобы партия из 1С успела прийти целиком: она
 * правит заказы построчно, и восемь писем за две минуты — это одно событие
 * глазами клиента, а не восемь.
 *
 * Задача одна на партию: повод, попавший в окно, дописывается в то же письмо
 * и новую задачу не ставит.
 */
class SendNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(private readonly int $letterId) {}

    public function handle(NotificationDispatcher $dispatcher): void
    {
        $letter = CrmEmail::query()->find($this->letterId);

        if ($letter === null || $letter->status === EmailStatus::SENT) {
            return;
        }

        $dispatcher->send($letter);
    }
}
