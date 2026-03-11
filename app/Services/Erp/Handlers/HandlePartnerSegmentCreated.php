<?php

namespace App\Services\Erp\Handlers;

use App\Models\PartnerSegment;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * US-12: Обработка события partner_segment.created из 1С.
 * Создаёт или обновляет сегмент партнёров и его состав.
 * Идемпотентен: повторная обработка обновляет существующий сегмент.
 */
class HandlePartnerSegmentCreated
{
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;
        $name = $payload['name'] ?? null;
        $partnerUuids = $payload['partner_uuids'] ?? [];

        if (!$uuid || !$name) {
            Log::warning('partner_segment.created: отсутствуют обязательные поля uuid или name', [
                'payload' => $payload,
            ]);
            return;
        }

        // Идемпотентный upsert сегмента
        $segment = PartnerSegment::updateOrCreate(
            ['uuid' => $uuid],
            ['name' => $name]
        );

        // Синхронизация партнёров по erp_id (UUID из 1С)
        $userIds = User::whereIn('erp_id', $partnerUuids)
            ->pluck('id');

        $segment->users()->sync($userIds);

        Log::info('partner_segment.created: сегмент создан/обновлён', [
            'uuid'          => $uuid,
            'name'          => $name,
            'partners_count' => $userIds->count(),
            'unknown_uuids' => count($partnerUuids) - $userIds->count(),
        ]);
    }
}
