<?php

namespace App\Notifications\Substitutions;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Еженедельный отчёт закупкам: повторные недоборы лечатся запасом, а не заменой.
 *
 * Менеджер продаж этот отчёт не собирает и не видит как задачу — он уходит
 * закупщику сам.
 */
class PurchasingShortageReportNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    /**
     * @param  list<array{name: string, shortages: int, lost_amount: float, stock: int, incoming: int}>  $rows
     */
    public function __construct(
        public array $rows,
        public int $windowDays,
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
        return (new MailMessage)
            ->subject(sprintf('Повторные недоборы за %d дней — отчёт закупкам — Pecado.ru', $this->windowDays))
            ->markdown('mail.substitutions.purchasing-report', [
                'rows' => $this->rows,
                'windowDays' => $this->windowDays,
            ]);
    }
}
