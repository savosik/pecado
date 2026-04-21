<?php

namespace App\Listeners;

use App\Jobs\PublishReturnToErpJob;

class PublishReturnToErp
{
    public function handle(object $event): void
    {
        if (! isset($event->productReturn)) {
            return;
        }

        $return = $event->productReturn;

        $return->load(['items.shipmentItem.shipment', 'items.product', 'user']);

        $payload = [
            'event' => 'return.created',
            'uuid' => $return->uuid,
            'partner_uuid' => $return->user?->erp_id,
            'timestamp' => now()->toIso8601String(),
            'items' => $return->items->map(function ($item) {
                $shipment = $item->shipmentItem?->shipment;

                return [
                    'product_uuid' => $item->product?->external_id,
                    'shipment_uuid' => $shipment?->uuid,
                    'shipment_number' => $shipment?->number,
                    'quantity' => (int) $item->quantity,
                    'price' => (float) $item->price,
                    'currency_code' => $shipment?->currency_code ?? 'RUB',
                    'subtotal' => (float) $item->subtotal,
                    'reason' => $item->reason?->value ?? $item->reason,
                ];
            })->toArray(),
        ];

        PublishReturnToErpJob::dispatch($payload);
    }
}
