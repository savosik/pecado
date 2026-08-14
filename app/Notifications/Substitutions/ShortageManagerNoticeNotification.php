<?php

namespace App\Notifications\Substitutions;

use App\Models\SubstitutionOffer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Тихое уведомление менеджеру: клиент отреагировал на подборку.
 *
 * Информирует, не требует действий — поэтому письмо, а не задача.
 * `kind`: 'viewed' — открыл, 'confirmed' — согласовал.
 */
class ShortageManagerNoticeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    /**
     * @param  list<string>  $orderNumbers  созданные заказы-замены (для confirmed)
     */
    public function __construct(
        public SubstitutionOffer $offer,
        public string $kind,
        public array $orderNumbers = [],
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
        $number = $this->offer->order?->erp_number ?: $this->offer->order?->number;
        $client = $this->offer->user?->name ?? 'Клиент';

        $mail = (new MailMessage)->greeting('Здравствуйте!');

        if ($this->kind === 'confirmed') {
            $mail->subject(sprintf('Клиент согласовал замену по заказу %s — Pecado.ru', $number))
                ->line(sprintf('%s согласовал подборку замен по заказу %s.', $client, $number));

            if ($this->orderNumbers !== []) {
                $mail->line('Созданы заказы-замены: '.implode(', ', $this->orderNumbers).'.');
            } else {
                $mail->line('Клиент отказался от всех предложенных замен — заказ-замена не создавался.');
            }
        } else {
            $mail->subject(sprintf('Клиент открыл подборку замен по заказу %s — Pecado.ru', $number))
                ->line(sprintf('%s открыл страницу подборки замен по заказу %s.', $client, $number));
        }

        return $mail->line('Действий не требуется — это информационное уведомление.')
            ->action('Открыть карточку недобора', url('/crm/shortages/'.$this->offer->id));
    }
}
