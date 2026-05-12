<?php

namespace App\Notifications\Health;

use Illuminate\Notifications\Messages\MailMessage;
use Spatie\Health\Notifications\CheckFailedNotification as BaseNotification;

/**
 * Локализованная версия CheckFailedNotification от Spatie.
 *
 * Подменяет английский subject «Application Health Check Failed» на русский
 * «Pecado.ru: проверка здоровья не пройдена», а тело шлёт через наш русский
 * markdown-шаблон. Подмена включается через `config/health.php` —
 * там этот класс заменяет дефолтный `Spatie\Health\Notifications\CheckFailedNotification`.
 */
class HealthCheckFailedNotification extends BaseNotification
{
    public function toMail(): MailMessage
    {
        $appName = (string) (config('app.name') ?: 'Pecado.ru');
        $env = app()->environment();

        return (new MailMessage)
            ->from(
                (string) config('health.notifications.mail.from.address', config('mail.from.address')),
                (string) config('health.notifications.mail.from.name', config('mail.from.name')),
            )
            ->subject(sprintf('%s: проверка здоровья не пройдена (%s)', $appName, $env))
            ->markdown('mail.health.check-failed', [
                'results' => $this->results,
                'appName' => $appName,
                'env' => $env,
            ]);
    }
}
