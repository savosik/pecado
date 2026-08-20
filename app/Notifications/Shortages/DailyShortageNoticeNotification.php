<?php

namespace App\Notifications\Shortages;

use App\Models\OrderItem;
use App\Models\PersonalManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Вечернее письмо менеджеру: сегодня по вашим клиентам были недоборы.
 *
 * Одно письмо в день на менеджера со всеми строками — не по строке на письмо:
 * недоборы приходят волнами по мере закрытия расходных ордеров, и десять писем
 * подряд читать никто не станет.
 *
 * Ссылка ведёт сразу в отфильтрованный журнал (сегодняшний день, без метки) —
 * менеджеру остаётся разнести строки, а не искать их.
 */
class DailyShortageNoticeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [30, 120, 300];

    /**
     * @param  Collection<int, OrderItem>  $items  неразнесённые строки дня
     * @param  PersonalManager|null  $onBehalfOf  чьи клиенты, если менеджер замещает коллегу
     */
    public function __construct(
        public Collection $items,
        public float $amount,
        public int $quantity,
        public int $ordersCount,
        public string $dayLabel,
        public string $journalUrl,
        public ?PersonalManager $onBehalfOf = null,
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
        $count = $this->items->count();

        return (new MailMessage)
            ->subject(sprintf('Недоборы за %s: %d %s — разнесите — Pecado.ru',
                $this->dayLabel,
                $count,
                $this->plural($count, 'позиция', 'позиции', 'позиций'),
            ))
            ->markdown('mail.shortages.daily-notice', [
                'items' => $this->items,
                'linesCount' => $count,
                'quantity' => $this->quantity,
                'amount' => $this->amount,
                'ordersCount' => $this->ordersCount,
                'dayLabel' => $this->dayLabel,
                'journalUrl' => $this->journalUrl,
                'onBehalfOf' => $this->onBehalfOf?->name,
            ]);
    }

    private function plural(int $count, string $one, string $few, string $many): string
    {
        $mod100 = $count % 100;
        $mod10 = $count % 10;

        return match (true) {
            $mod100 >= 11 && $mod100 <= 14 => $many,
            $mod10 === 1 => $one,
            $mod10 >= 2 && $mod10 <= 4 => $few,
            default => $many,
        };
    }
}
