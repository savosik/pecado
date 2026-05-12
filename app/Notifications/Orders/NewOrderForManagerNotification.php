<?php

namespace App\Notifications\Orders;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewOrderForManagerNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public Order $order,
    ) {
        $this->order->loadMissing(['items', 'user', 'company']);
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
        $clientName = $this->order->user?->name ?: $this->order->user?->email ?: '—';
        $company = $this->order->company?->name;

        return (new MailMessage)
            ->subject(sprintf('Новый заказ %s — Pecado.ru', $number))
            ->markdown('mail.orders.manager-new', [
                'order' => $this->order,
                'orderNumber' => $number,
                'clientName' => $clientName,
                'company' => $company,
                'adminUrl' => url(route('admin.orders.show', $this->order, false)),
            ]);
    }
}
