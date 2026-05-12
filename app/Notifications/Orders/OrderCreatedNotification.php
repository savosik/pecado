<?php

namespace App\Notifications\Orders;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public Order $order,
    ) {
        $this->order->loadMissing(['items', 'user']);
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $number = $this->order->erp_number ?: $this->order->number;
        $name = trim((string) ($notifiable->name ?? '')) ?: 'друг';

        return (new MailMessage)
            ->subject(sprintf('Заказ %s принят — Pecado.ru', $number))
            ->markdown('mail.orders.created', [
                'order' => $this->order,
                'orderNumber' => $number,
                'name' => $name,
                'orderUrl' => url(route('cabinet.orders.show', $this->order, false)),
            ]);
    }
}
