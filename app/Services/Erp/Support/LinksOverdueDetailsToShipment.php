<?php

namespace App\Services\Erp\Support;

use App\Models\ContractorBalanceOverdueDetail;
use App\Models\Shipment;
use Illuminate\Support\Facades\Log;

/**
 * Доклейка строк просроченной задолженности к пришедшей реализации.
 *
 * Баланс контрагента (`erp_in.balances`) и реализации (`erp_in.shipments`) идут
 * разными очередями с разным числом воркеров, поэтому детализация просрочки
 * регулярно приезжает раньше самого документа. Такая строка сохраняется с
 * `shipment_id = null`, а связь восстанавливается здесь — по `shipment_uuid`,
 * который остаётся источником правды.
 */
trait LinksOverdueDetailsToShipment
{
    /**
     * Связать осиротевшие строки просрочки с реализацией.
     *
     * Организацию строки заполняем только если она пуста: 1С могла прислать
     * её явно в самой детализации, и это значение приоритетнее (org-04).
     */
    protected function linkOverdueDetails(Shipment $shipment, string $context): void
    {
        $linked = ContractorBalanceOverdueDetail::query()
            ->where('shipment_uuid', $shipment->uuid)
            ->whereNull('shipment_id')
            ->update(['shipment_id' => $shipment->id]);

        if ($linked === 0) {
            return;
        }

        if ($shipment->organization_id) {
            ContractorBalanceOverdueDetail::query()
                ->where('shipment_id', $shipment->id)
                ->whereNull('organization_id')
                ->update(['organization_id' => $shipment->organization_id]);
        }

        Log::info($context.': строки просрочки связаны с реализацией', [
            'uuid' => $shipment->uuid,
            'shipment_id' => $shipment->id,
            'linked_count' => $linked,
        ]);
    }
}
