<?php

namespace App\Services\Erp\Support;

use App\Models\Shipment;
use App\Services\Payments\PaymentAllocationService;
use Illuminate\Support\Facades\Log;

/**
 * Доклейка строк разнесения платежей к пришедшей реализации.
 *
 * Прямой аналог LinksOverdueDetailsToShipment: платежи (`erp_in.payments`) и
 * реализации (`erp_in.documents`) идут разными очередями без гарантии порядка,
 * а первичная выгрузка гарантированно даёт часть платежей раньше их реализаций.
 * Такая строка сохраняется с `shipment_id = null`, связь восстанавливается здесь —
 * по `shipment_uuid`, который остаётся источником правды.
 */
trait LinksPaymentAllocationsToShipment
{
    protected function linkPaymentAllocations(Shipment $shipment, string $context): void
    {
        $linked = app(PaymentAllocationService::class)->linkOrphanAllocations($shipment);

        if ($linked === 0) {
            return;
        }

        Log::info($context.': строки разнесения платежей связаны с реализацией', [
            'uuid' => $shipment->uuid,
            'shipment_id' => $shipment->id,
            'linked_count' => $linked,
        ]);
    }
}
