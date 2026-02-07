<?php

namespace App\Listeners;

use App\Jobs\PublishOrderToErpJob;

class PublishOrderToErp
{
    /**
     * Handle the event.
     */
    public function handle(object $event): void
    {
        if (!isset($event->order)) {
            return;
        }

        $order = $event->order;
        
        // Load relationships to include in the payload
        $order->load(['items', 'deliveryAddress', 'user', 'company']);

        $orderData = $order->toArray();

        $payload = [
            'event' => class_basename($event),
            'timestamp' => now()->toIso8601String(),
            'order' => $orderData,
        ];

        PublishOrderToErpJob::dispatch($payload);
    }
}
