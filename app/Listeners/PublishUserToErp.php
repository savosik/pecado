<?php

namespace App\Listeners;

use App\Jobs\PublishUserToErpJob;

class PublishUserToErp
{
    /**
     * Handle the event.
     */
    public function handle(object $event): void
    {
        if (!isset($event->user)) {
            return;
        }

        if (class_basename($event) === 'UserUpdated') {
            $changes = $event->user->getChanges();
            
            if (!empty($changes)) {
                $significantChanges = array_filter(array_keys($changes), function($key) {
                    return !in_array($key, ['updated_at', 'currency_id']);
                });
                
                if (empty($significantChanges)) {
                    return;
                }
            }
        }

        $userData = $event->user->toArray();
        unset($userData['currency_id']);

        $payload = [
            'event' => class_basename($event),
            'timestamp' => now()->toIso8601String(),
            'user' => $userData,
        ];

        PublishUserToErpJob::dispatch($payload);
    }
}
