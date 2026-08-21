<?php

namespace App\Services\Crm\Mail;

/**
 * Справочник поводов: название и заготовка темы.
 *
 * Тонкая обёртка над `config/mail_occasions.php` — чтобы доменный код и списки
 * в интерфейсе спрашивали одно и то же место, а не читали конфиг вразнобой.
 */
class MailOccasions
{
    public function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /**
     * Название повода глазами менеджера. Незнакомый ключ возвращается как есть:
     * в сводке лучше показать технический ключ, чем пустую строку.
     */
    public function label(string $key): string
    {
        return (string) (self::all()[$key]['label'] ?? $key);
    }

    /**
     * Заготовка темы письма с подстановками вида {{order_number}}.
     */
    public function subjectTemplate(string $key): string
    {
        return (string) (self::all()[$key]['subject'] ?? self::all()[$key]['label'] ?? $key);
    }

    /**
     * Домен повода — часть ключа до точки. По нему гейтится сборка писем.
     */
    public function domain(string $key): string
    {
        return explode('.', $key)[0];
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function all(): array
    {
        return (array) config('mail_occasions', []);
    }
}
