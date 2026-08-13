<?php

namespace App\Services\Subscriptions;

/**
 * Реестр разделов кабинета, доступных для подписки на изменения.
 *
 * Тонкая обёртка над config/subscriptions.php: валидация ключа раздела,
 * получение метки и признака включённости. Используется контроллером
 * (валидация входящего section) и листенером-диспетчером (гейт рассылки).
 */
class SubscriptionRegistry
{
    /**
     * Все зарегистрированные разделы: ключ => метаданные.
     *
     * @return array<string, array{label: string, enabled: bool, events?: array<string, array{label: string, description?: string}>}>
     */
    public function all(): array
    {
        return (array) config('subscriptions.sections', []);
    }

    /**
     * Существует ли раздел в реестре (независимо от enabled).
     */
    public function exists(string $section): bool
    {
        return array_key_exists($section, $this->all());
    }

    /**
     * Включён ли раздел для подписок.
     */
    public function isEnabled(string $section): bool
    {
        return (bool) ($this->all()[$section]['enabled'] ?? false);
    }

    /**
     * Человекочитаемая метка раздела (фолбэк — сам ключ).
     */
    public function label(string $section): string
    {
        return (string) ($this->all()[$section]['label'] ?? $section);
    }

    /**
     * Каталог типов событий раздела: ключ => ['label' =>, 'description' =>].
     * Пустой массив — раздел без градации (подписчик получает все события).
     *
     * @return array<string, array{label: string, description?: string}>
     */
    public function events(string $section): array
    {
        return (array) ($this->all()[$section]['events'] ?? []);
    }

    /**
     * Ключи типов событий раздела.
     *
     * @return array<int, string>
     */
    public function eventKeys(string $section): array
    {
        return array_keys($this->events($section));
    }

    /**
     * Привести выбор подписчика к хранимому виду.
     *
     * Отбрасывает незнакомые ключи и сохраняет порядок из конфига. Возвращает
     * null (= «все типы»), если раздел без градации, выбор пуст или выбраны
     * все типы — тогда подписка автоматически подхватит и те события,
     * которые появятся в разделе позже.
     *
     * @param  array<int, string>|null  $selected
     * @return array<int, string>|null
     */
    public function normalizeEvents(string $section, ?array $selected): ?array
    {
        $known = $this->eventKeys($section);

        if ($known === [] || $selected === null) {
            return null;
        }

        $normalized = array_values(array_intersect($known, array_map('strval', $selected)));

        return ($normalized === [] || count($normalized) === count($known)) ? null : $normalized;
    }

    /**
     * Ключи только включённых разделов.
     *
     * @return array<int, string>
     */
    public function enabledKeys(): array
    {
        return array_keys(array_filter(
            $this->all(),
            static fn (array $meta): bool => (bool) ($meta['enabled'] ?? false)
        ));
    }
}
