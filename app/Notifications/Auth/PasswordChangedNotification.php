<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public string $source = 'cabinet',
        public ?string $ip = null,
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
        $name = trim((string) ($notifiable->name ?? '')) ?: 'пользователь';
        $sourceLabel = $this->source === 'reset'
            ? 'через ссылку для сброса пароля по email'
            : 'через личный кабинет';

        $admins = config('notifications.mail.admin_recipients', []);
        $supportEmail = is_array($admins) && ! empty($admins) ? $admins[0] : 'info@pecado.ru';

        return (new MailMessage)
            ->subject('Ваш пароль изменён — Pecado.ru')
            ->markdown('mail.auth.password-changed', [
                'name' => $name,
                'sourceLabel' => $sourceLabel,
                'changedAt' => now()->format('d.m.Y H:i'),
                'ip' => $this->ip,
                'supportEmail' => $supportEmail,
            ]);
    }
}
