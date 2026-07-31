<?php

namespace App\Notifications\Orders;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Одно письмо на одну покупку, когда оформление дало несколько документов.
 *
 * Корзина расщепляется по типам: наличие, предзаказ, уценка, промо-позиции,
 * рекламные образцы. Для клиента это одна покупка, и письмо должно быть одно —
 * со списком всех документов и их назначением.
 *
 * Когда документ ровно один, используется обычная `OrderCreatedNotification`:
 * перечислять «документы» там, где он единственный, только запутывает.
 */
class OrdersPlacedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    /**
     * @param  Collection<int, Order>  $orders
     */
    public function __construct(public Collection $orders)
    {
        // Коллекция может быть базовой (её собирает OrderAssembler), поэтому
        // подгружаем по одной модели, а не через loadMissing коллекции
        $this->orders->each(fn (Order $order) => $order->loadMissing(['items', 'user']));
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
        $name = trim((string) ($notifiable->name ?? '')) ?: 'друг';
        $total = $this->orders->sum(fn (Order $order) => (float) $order->total_amount);

        return (new MailMessage)
            ->subject('Заказ принят — Pecado.ru')
            ->markdown('mail.orders.placed', [
                'orders' => $this->orders,
                'name' => $name,
                'total' => $total,
                'currency' => $this->orders->first()->currency_code ?? '₽',
                'ordersUrl' => url(route('cabinet.orders.index', absolute: false)),
            ]);
    }
}
