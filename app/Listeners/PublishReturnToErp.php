<?php

namespace App\Listeners;

use App\Jobs\PublishReturnToErpJob;

class PublishReturnToErp
{
    /**
     * Handle the event.
     */
    public function handle(object $event): void
    {
        if (!isset($event->productReturn)) {
            return;
        }

        $return = $event->productReturn;

        // Load relationships to include in the payload
        $return->load(['items.product', 'order', 'user']);

        $payload = [
            'event' => 'return.created',
            'uuid' => $return->uuid,
            'order_uuid' => $return->order?->uuid,
            'partner_uuid' => $return->user?->erp_id,
            'timestamp' => now()->toIso8601String(),
            'items' => $return->items->map(function ($item) {
                return [
                    'product_uuid' => $item->product?->external_id,
                    'quantity' => $item->quantity,
                    'reason' => $item->reason?->value ?? $item->reason,
                ];
            })->toArray(),
        ];

        PublishReturnToErpJob::dispatch($payload);
    }
}
