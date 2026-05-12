<?php

namespace App\Notifications\Auth;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends BaseResetPassword implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function toMail($notifiable): MailMessage
    {
        if (static::$toMailCallback) {
            return call_user_func(static::$toMailCallback, $notifiable, $this->token);
        }

        $url = $this->resetUrl($notifiable);
        $broker = config('auth.defaults.passwords');
        $minutes = (int) config("auth.passwords.{$broker}.expire", 60);

        return (new MailMessage)
            ->subject('Сброс пароля — Pecado.ru')
            ->markdown('mail.auth.reset-password', [
                'url' => $url,
                'minutes' => $minutes,
                'name' => $notifiable->name ?? null,
            ]);
    }
}
