<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class PublishUserToErp
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
        if (!isset($event->user)) {
            return;
        }

        $payload = [
            'event' => class_basename($event),
            'timestamp' => now()->toIso8601String(),
            'user' => $event->user->toArray(),
        ];

        \Illuminate\Support\Facades\Queue::connection('rabbitmq')->pushRaw(
            json_encode($payload),
            'erp_users'
        );
    }
}
