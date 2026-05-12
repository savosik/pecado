<?php

namespace App\Listeners;

use App\Events\ReturnStatusChanged;
use App\Notifications\Returns\ReturnStatusChangedNotification;

class SendReturnStatusChangedEmail
{
    public function handle(ReturnStatusChanged $event): void
    {
        if (! config('notifications.mail.features.return_status_changes')) {
            return;
        }

        $user = $event->productReturn->user;

        if (blank($user->email)) {
            return;
        }

        $user->notify(new ReturnStatusChangedNotification(
            $event->productReturn,
            $event->previousStatus,
        ));
    }
}
