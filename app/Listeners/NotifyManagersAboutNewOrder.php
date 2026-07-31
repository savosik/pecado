<?php

namespace App\Listeners;

use App\Enums\OrderType;
use App\Events\OrdersPlaced;
use App\Models\Order;
use App\Notifications\Orders\NewOrderForManagerNotification;
use App\Support\Notifications\OrderManagerRouting;
use Illuminate\Support\Facades\Notification;

/**
 * Уведомление менеджерам о новом оформлении.
 *
 * Слушает покупку, а не документ: расщепление корзины по типам даёт до пяти
 * заказов, и пять писем об одной покупке — шум, в котором теряется само событие.
 * Письмо уходит по основному документу; остальные менеджер видит в 1С и в CRM,
 * они связаны одной корзиной.
 */
class NotifyManagersAboutNewOrder
{
    public function handle(OrdersPlaced $event): void
    {
        if (! config('notifications.mail.features.manager_new_order')) {
            return;
        }

        $primary = $this->primaryOrder($event->orders);

        if (! $primary) {
            return;
        }

        $recipients = OrderManagerRouting::recipients($primary);

        if (empty($recipients)) {
            return;
        }

        Notification::route('mail', $recipients)
            ->notify(new NewOrderForManagerNotification($primary));
    }

    /**
     * Основной документ оформления.
     *
     * Обычный заказ, иначе предзаказ, иначе первый попавшийся: промо и уценка
     * без основного заказа существовать могут, но встречаются редко, и письмо
     * лучше отправить по ним, чем не отправить вовсе.
     *
     * @param  \Illuminate\Support\Collection<int, Order>  $orders
     */
    private function primaryOrder($orders): ?Order
    {
        foreach ([OrderType::ORDER, OrderType::PREORDER] as $type) {
            if ($order = $orders->first(fn (Order $o) => $o->type === $type)) {
                return $order;
            }
        }

        return $orders->first();
    }
}
