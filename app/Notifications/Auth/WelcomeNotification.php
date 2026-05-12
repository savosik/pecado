<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public string $source = 'web',
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = trim((string) ($notifiable->name ?? '')) ?: 'друг';
        $cabinetUrl = url('/cabinet/profile');

        return (new MailMessage)
            ->subject('Добро пожаловать в Pecado.ru')
            ->markdown('mail.auth.welcome', [
                'name' => $name,
                'cabinetUrl' => $cabinetUrl,
            ]);
    }
}
