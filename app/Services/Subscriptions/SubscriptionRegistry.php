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
     * @return array<string, array{label: string, enabled: bool}>
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
