<?php

namespace App\Services\Client\Api\Idempotency;

use RuntimeException;

/**
 * Ключ идемпотентности использован неверно: отсутствует там, где обязателен,
 * повторён с другим телом или ещё выполняется параллельно.
 */
class IdempotencyConflict extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $meta = [],
    ) {
        parent::__construct($message);
    }

    public static function required(string $header): self
    {
        return new self(
            'idempotency_key_required',
            'Для этой операции обязателен заголовок '.$header.': повторный вызов с тем же ключом не создаст дубль.',
        );
    }

    public static function reused(): self
    {
        return new self(
            'idempotency_key_reused',
            'Этот ключ идемпотентности уже использован с другими аргументами. Для нового вызова возьмите новый ключ.',
        );
    }

    public static function inProgress(): self
    {
        return new self(
            'idempotency_in_progress',
            'Запрос с этим ключом ещё выполняется. Повторите через несколько секунд с тем же ключом.',
            409,
        );
    }
}
