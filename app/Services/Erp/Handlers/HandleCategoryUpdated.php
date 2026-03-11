<?php

namespace App\Services\Erp\Handlers;

/**
 * US-13: Обработка события category.updated из 1С.
 * Делегирует в HandleCategoryCreated — логика upsert идентична.
 */
class HandleCategoryUpdated
{
    public function __construct(private readonly HandleCategoryCreated $inner) {}

    public function handle(array $payload): void
    {
        $this->inner->handle($payload);
    }
}
