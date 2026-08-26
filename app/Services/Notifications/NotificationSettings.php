<?php

namespace App\Services\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Support\Notifications\Destination;

/**
 * Действующая настройка уведомления у партнёра: отклонение или умолчание.
 *
 * Единственное место, которое решает «что сейчас в силе». И экран, и отправка
 * спрашивают его — иначе интерфейс показывал бы одно, а уходило бы другое.
 */
class NotificationSettings
{
    public function __construct(private readonly NotificationCatalog $catalog) {}

    /**
     * @return array{enabled: bool, destinations: list<Destination>, options: array<string, mixed>, overridden: bool, changed_by_client: bool}
     */
    public function effective(?int $clientUserId, string $occasionKey): array
    {
        $row = $clientUserId === null
            ? null
            : NotificationPreference::query()
                ->where('user_id', $clientUserId)
                ->where('occasion_key', $occasionKey)
                ->first();

        if ($row === null) {
            return [
                'enabled' => $this->catalog->enabledByDefault($occasionKey),
                'destinations' => $this->catalog->defaultDestinations($occasionKey),
                'options' => $this->catalog->defaultOptions($occasionKey),
                'overridden' => false,
                'changed_by_client' => false,
            ];
        }

        // Отклонение может касаться только выключателя: тогда адресаты
        // и настройки остаются умолчанием, а не обнуляются.
        $destinations = $row->destinations === null
            ? $this->catalog->defaultDestinations($occasionKey)
            : $this->parse((array) $row->destinations);

        return [
            'enabled' => (bool) $row->is_enabled,
            'destinations' => $destinations,
            'options' => $row->options === null
                ? $this->catalog->defaultOptions($occasionKey)
                : (array) $row->options,
            'overridden' => true,
            'changed_by_client' => (bool) $row->changed_by_client,
        ];
    }

    /**
     * Сохранить настройку. Совпадение с умолчанием удаляет строку —
     * тогда партнёр снова следует за конфигом, а не за его старой копией.
     *
     * @param  list<array<string, mixed>>  $destinations
     * @param  array<string, mixed>  $options
     */
    public function save(
        User $client,
        string $occasionKey,
        bool $enabled,
        array $destinations,
        array $options,
        ?User $actor,
        bool $byClient = false,
    ): void {
        $parsed = $this->parse($destinations);

        if ($this->matchesDefault($occasionKey, $enabled, $parsed, $options)) {
            NotificationPreference::query()
                ->where('user_id', $client->getKey())
                ->where('occasion_key', $occasionKey)
                ->delete();

            return;
        }

        NotificationPreference::query()->updateOrCreate(
            ['user_id' => $client->getKey(), 'occasion_key' => $occasionKey],
            [
                'is_enabled' => $enabled,
                'destinations' => array_map(fn (Destination $d): array => $d->toArray(), $parsed),
                'options' => $options === [] ? null : $options,
                'changed_by_client' => $byClient,
                'updated_by_user_id' => $actor?->getKey(),
            ],
        );
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<Destination>
     */
    public function parse(array $rows): array
    {
        return array_values(array_filter(array_map(
            fn ($row): ?Destination => is_array($row) ? Destination::fromArray($row) : null,
            $rows,
        )));
    }

    /**
     * @param  list<Destination>  $destinations
     * @param  array<string, mixed>  $options
     */
    private function matchesDefault(string $key, bool $enabled, array $destinations, array $options): bool
    {
        if ($enabled !== $this->catalog->enabledByDefault($key)) {
            return false;
        }

        $mine = array_map(fn (Destination $d): array => $d->toArray(), $destinations);
        $theirs = array_map(fn (Destination $d): array => $d->toArray(), $this->catalog->defaultDestinations($key));

        if ($mine !== $theirs) {
            return false;
        }

        $defaultOptions = $this->catalog->defaultOptions($key);

        return ($options === [] ? $defaultOptions : $options) == $defaultOptions;
    }
}
