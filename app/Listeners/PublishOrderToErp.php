<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Queue;

class PublishOrderToErp
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

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

        Queue::connection('rabbitmq')->pushRaw(
            json_encode($payload),
            'erp_orders'
        );
    }
}
