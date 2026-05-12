<?php

namespace App\Listeners;

use App\Events\OrderUpdated;
use App\Notifications\Orders\OrderStatusChangedNotification;

class SendOrderStatusChangedEmail
{
    public function handle(OrderUpdated $event): void
    {
        if (! config('notifications.mail.features.order_status_changes')) {
            return;
        }

        $order = $event->order;

        if (! $order->wasChanged('status')) {
            return;
        }

        // Клиенту шлём только при физических переходах из 1С (отгрузили, оплатили, закрыли).
        // Ручные правки админа клиенту не уходят — менеджер сам объясняет.
        if (! $order->fromErp) {
            return;
        }

        $whitelist = (array) config('notifications.mail.order_statuses_to_notify_client', []);

        if (! in_array($order->status->value, $whitelist, true)) {
            return;
        }

        $user = $order->user;

        if (! $user || blank($user->email)) {
            return;
        }

        $user->notify(new OrderStatusChangedNotification($order, $order->previousStatus));
    }
}
