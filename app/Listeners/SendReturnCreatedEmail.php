<?php

namespace App\Listeners;

use App\Events\ReturnCreated;
use App\Notifications\Returns\ReturnCreatedNotification;

class SendReturnCreatedEmail
{
    public function handle(ReturnCreated $event): void
    {
        if (! config('notifications.mail.features.return_created')) {
            return;
        }

        $user = $event->productReturn->user;

        if (blank($user->email)) {
            return;
        }

        $user->notify(new ReturnCreatedNotification($event->productReturn));
    }
}
