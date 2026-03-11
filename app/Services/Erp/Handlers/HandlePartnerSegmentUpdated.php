<?php

namespace App\Services\Erp\Handlers;

use Illuminate\Support\Facades\Log;

/**
 * US-12: Обработка события partner_segment.updated из 1С.
 * Обновляет существующий сегмент партнёров.
 * Делегирует на HandlePartnerSegmentCreated — тот же идемпотентный upsert.
 */
class HandlePartnerSegmentUpdated
{
    public function __construct(
        private readonly HandlePartnerSegmentCreated $handleCreated,
    ) {}

    public function handle(array $payload): void
    {
        Log::info('partner_segment.updated: делегирование на created (upsert)', [
            'uuid' => $payload['uuid'] ?? null,
            'name' => $payload['name'] ?? null,
        ]);

        $this->handleCreated->handle($payload);
    }
}
