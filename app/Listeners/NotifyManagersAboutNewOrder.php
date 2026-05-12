<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Notifications\Orders\NewOrderForManagerNotification;
use App\Support\Notifications\OrderManagerRouting;
use Illuminate\Support\Facades\Notification;

class NotifyManagersAboutNewOrder
{
    public function handle(OrderCreated $event): void
    {
        if (! config('notifications.mail.features.manager_new_order')) {
            return;
        }

        $recipients = OrderManagerRouting::recipients($event->order);

        if (empty($recipients)) {
            return;
        }

        Notification::route('mail', $recipients)
            ->notify(new NewOrderForManagerNotification($event->order));
    }
}
