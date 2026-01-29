<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Queue;

class PublishCompanyToErp
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
        if (!isset($event->company)) {
            return;
        }

        // Load bank accounts and user to include in ERP payload
        $company = $event->company->load(['bankAccounts', 'user']);

        $payload = [
            'event' => class_basename($event),
            'timestamp' => now()->toIso8601String(),
            'company' => $company->toArray(),
            'user' => [
                'id' => $company->user->id,
                'erp_id' => $company->user->erp_id,
            ],
        ];

        Queue::connection('rabbitmq')->pushRaw(
            json_encode($payload),
            'erp_events'
        );
    }
}
