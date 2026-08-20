<?php

namespace App\Services\Notifications\Pulse;

use App\Models\NotificationRule;

/**
 * Раскрытый адресат: конкретный адрес плюс след того, откуда он взялся.
 *
 * След нужен журналу: менеджер должен видеть не только «письмо ушло на
 * buh@romashka.ru», но и «потому что правило X адресовано роли бухгалтер».
 */
class ResolvedRecipient
{
    public function __construct(
        public readonly string $email,
        public readonly string $kind,
        public readonly NotificationRule $rule,
        public readonly ?int $contactId = null,
        public readonly string $copyType = 'to',
        public readonly bool $isFallback = false,
    ) {}

    /**
     * Ключ дедупликации: один адрес получает письмо один раз за сигнал,
     * даже если его добавили несколько правил.
     */
    public function key(): string
    {
        return mb_strtolower(trim($this->email));
    }
}
