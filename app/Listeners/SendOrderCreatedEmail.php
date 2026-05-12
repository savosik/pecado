<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Notifications\Orders\OrderCreatedNotification;

class SendOrderCreatedEmail
{
    public function handle(OrderCreated $event): void
    {
        if (! config('notifications.mail.features.order_created')) {
            return;
        }

        $user = $event->order->user;

        if (! $user || blank($user->email)) {
            return;
        }

        $user->notify(new OrderCreatedNotification($event->order));
    }
}
