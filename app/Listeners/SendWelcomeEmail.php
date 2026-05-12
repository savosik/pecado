<?php

namespace App\Listeners;

use App\Events\UserRegisteredOnSite;
use App\Notifications\Auth\WelcomeNotification;

class SendWelcomeEmail
{
    public function handle(UserRegisteredOnSite $event): void
    {
        if (! config('notifications.mail.features.welcome_on_registration')) {
            return;
        }

        $user = $event->user;

        if (filled($user->erp_id)) {
            // Контрагенты из 1С — welcome не отправляем, у них свой канал общения через менеджера.
            return;
        }

        if (blank($user->email)) {
            return;
        }

        $user->notify(new WelcomeNotification($event->source));
    }
}
