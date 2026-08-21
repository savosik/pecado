<?php

namespace App\Listeners;

use App\Events\OrdersPlaced;
use App\Notifications\Orders\OrderCreatedNotification;
use App\Notifications\Orders\OrdersPlacedNotification;

/**
 * Одно оформление — одно письмо клиенту.
 *
 * Раньше письмо слушало `OrderCreated`, то есть **документ**, и покупка из трёх
 * документов (наличие + предзаказ + уценка) давала клиенту три письма. С промо
 * их стало бы пять.
 *
 * Теперь слушаем `OrdersPlaced` — событие про покупку. `OrderCreated` остаётся
 * для обмена с 1С, где каждый документ действительно уезжает отдельно.
 */
class SendOrdersPlacedEmail
{
    public function handle(OrdersPlaced $event): void
    {
        if (! config('notifications.mail.features.order_created')) {
            return;
        }

        $user = $event->user;

        if (! $user || blank($user->email)) {
            return;
        }

        // Рекламные образцы клиенту не выписываются и в письме не нужны:
        // он их не заказывал и в накладной не увидит
        $orders = $event->orders->reject(
            fn ($order) => $order->type === \App\Enums\OrderType::PROMO_SAMPLE,
        )->values();

        if ($orders->isEmpty()) {
            return;
        }

        // Один документ — обычное письмо: перечислять «документы» там,
        // где он единственный, только запутывает
        $user->notify($orders->count() === 1
            ? new OrderCreatedNotification($orders->first())
            : new OrdersPlacedNotification($orders));
    }
}
