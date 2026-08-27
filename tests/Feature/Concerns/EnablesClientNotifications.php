<?php

namespace Tests\Feature\Concerns;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Notifications\NotificationCatalog;

/**
 * Включить клиенту уведомления в тесте.
 *
 * С 27.08.2026 клиентские уведомления выключены умолчанием: заказчик решил
 * начать с тишины и настраивать каждому партнёру индивидуально. Тестам,
 * которые проверяют не саму настройку, а поведение потока, нужно явно
 * сказать «этот партнёр подписан» — иначе они проверяют тишину.
 */
trait EnablesClientNotifications
{
    /**
     * @param  list<string>|null  $keys  ключи поводов; null — все клиентские
     */
    protected function enableNotificationsFor(User $client, ?array $keys = null): void
    {
        $catalog = app(NotificationCatalog::class);
        $keys ??= $catalog->clientVisibleKeys();

        foreach ($keys as $key) {
            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $client->getKey(), 'occasion_key' => $key],
                [
                    'is_enabled' => true,
                    'destinations' => $catalog->defaultDestinations($key) === []
                        ? [['type' => 'login']]
                        : array_map(fn ($d): array => $d->toArray(), $catalog->defaultDestinations($key)),
                ],
            );
        }
    }
}
