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
     * Все поводы, которые система умеет: ключ, название, домен.
     *
     * Реестр нужен экрану «Поводы»: подписать партнёра можно только на то,
     * что видно, а до этого список событий существовал лишь в конфиге.
     *
     * @return list<array{key: string, label: string, subject: string, domain: string}>
     */
    public function catalog(): array
    {
        $catalog = [];

        foreach (self::all() as $key => $occasion) {
            $catalog[] = [
                'key' => (string) $key,
                'label' => (string) ($occasion['label'] ?? $key),
                'subject' => (string) ($occasion['subject'] ?? ''),
                'domain' => $this->domain((string) $key),
            ];
        }

        return $catalog;
    }

    /**
     * Собирает ли система письма этого домена. Выключенный домен объясняет
     * пустой счётчик лучше, чем ноль без пояснения.
     */
    public function domainEnabled(string $domain): bool
    {
        return (bool) config('mail_stream.domains.'.$domain, false);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function all(): array
    {
        return (array) config('mail_occasions', []);
    }
}
