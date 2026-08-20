<?php

namespace App\Services\Notifications\Pulse;

use App\Models\NotificationRule;
use App\Models\NotificationRuleRecipient;

/**
 * Накопитель адресатов при разборе правил.
 *
 * Три свойства, которые он обеспечивает:
 *
 * 1. Дубль получает письмо один раз. Правило с меньшим приоритетом «главнее» —
 *    оно и определяет шаблон, тему и запись в журнале, потому что разбор идёт
 *    сверху вниз и первым пришедший адресат остаётся.
 * 2. Правило-исключение вычёркивает адрес, добавленный правилами ниже, —
 *    это Sieve discard в применении к одному адресату.
 * 3. Резервный адресат добавляется, только если основных не нашлось.
 */
class RecipientBag
{
    /** @var array<string, ResolvedRecipient> */
    private array $recipients = [];

    /** @var array<string, ResolvedRecipient> */
    private array $fallbacks = [];

    /** @var array<int, string> */
    private array $suppressed = [];

    /**
     * @param  array<int, ResolvedRecipient>  $resolved
     */
    public function apply(NotificationRule $rule, array $resolved): void
    {
        foreach ($resolved as $recipient) {
            if ($recipient->kind === NotificationRuleRecipient::KIND_SUPPRESS) {
                $this->suppressed[] = $recipient->key();

                continue;
            }

            $bucket = $recipient->isFallback ? 'fallbacks' : 'recipients';

            // Первым пришедший остаётся: у него правило с меньшим приоритетом.
            if (! array_key_exists($recipient->key(), $this->{$bucket})) {
                $this->{$bucket}[$recipient->key()] = $recipient;
            }
        }
    }

    /**
     * Итоговый список адресатов.
     *
     * @return array<int, ResolvedRecipient>
     */
    public function all(): array
    {
        $result = $this->recipients;

        // Резерв нужен, только если основных адресатов не нашлось вовсе:
        // «заказ ничьего клиента не должен остаться незамеченным».
        if ($result === []) {
            $result = $this->fallbacks;
        }

        foreach ($this->suppressed as $key) {
            unset($result[$key]);
        }

        return array_values($result);
    }

    public function isEmpty(): bool
    {
        return $this->all() === [];
    }
}
