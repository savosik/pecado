<?php

namespace App\Listeners;

use App\Models\NotificationSuppression;
use Illuminate\Support\Facades\Log;

/**
 * Стоп-лист по отказам почтового сервера.
 *
 * Мотив прикладной: адрес уволившегося бухгалтера отбивается сервером на каждом
 * письме, а репутация отправителя падает для всех писем домена, включая заказы.
 *
 * Жёсткий отказ (адреса не существует) кладёт адрес в стоп-лист; мягкий
 * (ящик переполнен, временная недоступность) не кладёт: такой адрес завтра
 * может заработать, и вычёркивать его насовсем нельзя.
 *
 * Вызывается из `SendCrmEmailJob::failed()` — то есть с настоящего пути
 * отправки, а не с гипотетического.
 */
class RecordMailBounce
{
    /**
     * Признаки того, что адрес не существует и слать на него бессмысленно.
     */
    private const HARD_MARKERS = [
        'user unknown',
        'no such user',
        'does not exist',
        'mailbox unavailable',
        'invalid recipient',
        'recipient address rejected',
        'address rejected',
        '550',
        '553',
    ];

    /**
     * @param  array<int, string>  $recipients  адреса письма, которое не ушло
     */
    public function handleFailure(array $recipients, string $error): void
    {
        if (! $this->isHardBounce($error)) {
            return;
        }

        foreach ($recipients as $recipient) {
            $email = mb_strtolower(trim((string) $recipient));

            if ($email === '') {
                continue;
            }

            NotificationSuppression::updateOrCreate(
                ['email' => $email, 'scope' => NotificationSuppression::SCOPE_ALL],
                [
                    'reason' => NotificationSuppression::REASON_BOUNCE,
                    'note' => mb_substr($error, 0, 500),
                ],
            );

            Log::warning('Почта: адрес отвергнут почтовым сервером', ['recipient' => $email]);
        }
    }

    private function isHardBounce(string $error): bool
    {
        $haystack = mb_strtolower($error);

        foreach (self::HARD_MARKERS as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }
}
