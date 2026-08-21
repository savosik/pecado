<?php

namespace App\Support\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Повод для письма: что случилось, у кого, с какими числами.
 *
 * Доменный код на этом заканчивает. Кому уйдёт письмо и уйдёт ли вообще —
 * дело правил-фильтров, а не места, где повод случился.
 *
 * `view` — то, из чего собирается тело письма: заголовок, текст и блоки
 * изменений. `data` — числа для условий правил и для меток-ступеней.
 */
class Occasion
{
    /**
     * @param  string  $key  ключ повода из config/mail_occasions.php
     * @param  array<string, mixed>  $data  поля, доступные условиям правил
     * @param  array<string, mixed>  $view  данные для тела письма (title, body, url, rows)
     */
    public function __construct(
        public readonly string $key,
        public readonly ?int $clientUserId = null,
        public readonly ?int $companyId = null,
        public readonly ?Model $subject = null,
        public readonly array $data = [],
        public readonly array $view = [],
        public readonly ?CarbonInterface $occurredAt = null,
    ) {}

    public function occurredAtOrNow(): CarbonInterface
    {
        return $this->occurredAt ?? now();
    }

    public function domain(): string
    {
        return explode('.', $this->key)[0];
    }
}
