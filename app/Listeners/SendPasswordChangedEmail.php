<?php

namespace App\Listeners;

use App\Events\UserPasswordChanged;
use App\Notifications\Auth\PasswordChangedNotification;

class SendPasswordChangedEmail
{
    public function handle(UserPasswordChanged $event): void
    {
        if (! config('notifications.mail.features.password_changed')) {
            return;
        }

        $user = $event->user;

        if (blank($user->email)) {
            return;
        }

        $user->notify(new PasswordChangedNotification($event->source, $event->ip));
    }
}
