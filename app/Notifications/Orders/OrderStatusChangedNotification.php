<?php

namespace App\Notifications\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderStatusChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public Order $order,
        public ?OrderStatus $previousStatus = null,
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
        $number = $this->order->erp_number ?: $this->order->number;
        $name = trim((string) ($notifiable->name ?? '')) ?: 'друг';

        return (new MailMessage)
            ->subject(sprintf('Заказ %s: %s — Pecado.ru', $number, $this->order->status->label()))
            ->markdown('mail.orders.status-changed', [
                'order' => $this->order,
                'orderNumber' => $number,
                'name' => $name,
                'newStatus' => $this->order->status,
                'previousStatus' => $this->previousStatus,
                'message' => $this->humanMessage(),
                'orderUrl' => url(route('cabinet.orders.show', $this->order, false)),
            ]);
    }

    private function humanMessage(): string
    {
        return match ($this->order->status) {
            OrderStatus::READY_FOR_SHIPMENT => 'Ваш заказ собран и готов к отгрузке. Скоро мы передадим его в доставку.',
            OrderStatus::SHIPPING => 'Заказ отгружен и едет к вам. Если вы оформляли самовывоз — приезжайте за ним.',
            OrderStatus::AWAITING_PAYMENT => 'Заказ ожидает оплаты. Пожалуйста, оплатите его, чтобы мы могли продолжить.',
            OrderStatus::CLOSED => 'Заказ закрыт. Спасибо, что выбираете Pecado.ru!',
            default => 'Статус вашего заказа обновлён. Подробности — в личном кабинете.',
        };
    }
}
