<?php

namespace App\Services\Notifications;

use App\Support\Notifications\Destination;

/**
 * Каталог уведомлений: что система умеет присылать и кому по умолчанию.
 *
 * Тонкая обёртка над config/mail_occasions.php. Умолчание живёт рядом с самим
 * уведомлением намеренно: добавил тип — тут же сказал, кому он идёт, и нет
 * второго места, которое может с этим разойтись.
 */
class NotificationCatalog
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return (array) config('mail_occasions', []);
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function label(string $key): string
    {
        return (string) ($this->all()[$key]['label'] ?? $key);
    }

    /**
     * Показывать ли тип клиенту в кабинете. Внутренние уведомления
     * (вопрос с сайта, письмо менеджеру) клиент видеть не должен.
     */
    public function visibleToClient(string $key): bool
    {
        return (bool) ($this->all()[$key]['client_visible'] ?? false);
    }

    public function enabledByDefault(string $key): bool
    {
        return (bool) ($this->all()[$key]['default_enabled'] ?? false);
    }

    /**
     * @return list<Destination>
     */
    public function defaultDestinations(string $key): array
    {
        $rows = (array) ($this->all()[$key]['default_destinations'] ?? []);

        return array_values(array_filter(array_map(
            fn ($row): ?Destination => is_array($row) ? Destination::fromArray($row) : null,
            $rows,
        )));
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultOptions(string $key): array
    {
        return (array) ($this->all()[$key]['default_options'] ?? []);
    }

    /**
     * Семейство типа — часть ключа до точки. По нему уведомления
     * группируются на экране: «Заказы», «Документы», «Финансы».
     */
    public function family(string $key): string
    {
        return explode('.', $key)[0];
    }

    public function familyLabel(string $key): string
    {
        return match ($this->family($key)) {
            'orders' => 'Заказы',
            'documents' => 'Документы',
            'finance' => 'Финансы',
            'system' => 'Прочее',
            default => 'Прочее',
        };
    }

    /**
     * Типы, которые видит клиент, — в порядке конфига.
     *
     * @return list<string>
     */
    public function clientVisibleKeys(): array
    {
        return array_values(array_filter(
            array_keys($this->all()),
            fn (string $key): bool => $this->visibleToClient($key),
        ));
    }
}
