<?php

namespace App\Services\Erp\Handlers;

use App\Models\PartnerSegment;
use Illuminate\Support\Facades\Log;

/**
 * US-12: Обработка события partner_segment.deleted из 1С.
 * Удаляет сегмент партнёров (каскадно удаляет привязки в partner_user).
 */
class HandlePartnerSegmentDeleted
{
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;

        if (!$uuid) {
            Log::warning('partner_segment.deleted: отсутствует поле uuid', [
                'payload' => $payload,
            ]);
            return;
        }

        $segment = PartnerSegment::where('uuid', $uuid)->first();

        if (!$segment) {
            Log::info('partner_segment.deleted: сегмент не найден, пропускаем', [
                'uuid' => $uuid,
            ]);
            return;
        }

        $segment->delete();

        Log::info('partner_segment.deleted: сегмент удалён', [
            'uuid' => $uuid,
        ]);
    }
}
