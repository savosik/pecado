<?php

namespace App\Services\Assistant\Gateway;

use RuntimeException;
use Throwable;

/**
 * Ошибка шлюза, переведённая с языка SDK на язык помощника.
 *
 * Воркеру и флагу доступности важно не «какой класс исключения», а «что
 * делать»: кончился баланс или закрыт доступ — прятать помощника у всех;
 * перегрузка или лимит частоты — попросить клиента повторить позже.
 */
final class GatewayException extends RuntimeException
{
    /** Баланс, лимит workspace, платёжная проблема — помощник исчезает. */
    public const BILLING = 'billing';

    /** Ключ не принят — помощник исчезает. */
    public const AUTH = 'auth';

    /** Доступ закрыт (регион, права организации) — помощник исчезает. */
    public const FORBIDDEN = 'forbidden';

    /** Прокси или сеть — помощник исчезает до успешной пробы. */
    public const CONNECTION = 'connection';

    /** Слишком часто — временно, повторить позже. */
    public const RATE_LIMIT = 'rate_limit';

    /** Anthropic перегружен или 5xx — временно. */
    public const OVERLOADED = 'overloaded';

    /** Не дождались ответа — временно. */
    public const TIMEOUT = 'timeout';

    /** Наш запрос неверен (400/422) — ошибка кода, не доступности. */
    public const INVALID = 'invalid';

    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    /** Ошибка, после которой помощник должен исчезнуть у всех до успешной пробы. */
    public function disablesAssistant(): bool
    {
        return in_array($this->kind, [self::BILLING, self::AUTH, self::FORBIDDEN, self::CONNECTION], true);
    }

    /** Ошибка, после которой стоит повторить позже без смены состояния. */
    public function isTransient(): bool
    {
        return in_array($this->kind, [self::RATE_LIMIT, self::OVERLOADED, self::TIMEOUT], true);
    }
}
