<?php

namespace App\Services\Erp\Handlers;

/**
 * US-13: Обработка события product.updated из 1С.
 * Делегирует в HandleProductCreated — логика upsert идентична.
 */
class HandleProductUpdated
{
    public function __construct(private readonly HandleProductCreated $inner) {}

    public function handle(array $payload): void
    {
        $this->inner->handle($payload);
    }
}
