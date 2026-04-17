<?php

namespace App\Services\Erp\Handlers;

use App\Models\Shipment;
use Illuminate\Support\Facades\Log;

class HandleShipmentDeleted
{
    /**
     * Обработка события shipment.deleted из 1С.
     * Soft-delete реализации по UUID.
     */
    public function handle(array $payload): void
    {
        $uuid = $payload['uuid'] ?? null;

        if (! $uuid) {
            Log::warning('HandleShipmentDeleted: отсутствует uuid', ['payload' => $payload]);

            return;
        }

        $shipment = Shipment::where('uuid', $uuid)->first();

        if (! $shipment) {
            Log::info('HandleShipmentDeleted: реализация не найдена', ['uuid' => $uuid]);

            return;
        }

        $shipment->delete();

        Log::info('HandleShipmentDeleted: реализация удалена (soft-delete)', ['uuid' => $uuid]);
    }
}
