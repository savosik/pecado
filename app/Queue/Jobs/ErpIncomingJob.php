<?php

namespace App\Queue\Jobs;

use App\Jobs\ProcessErpUserUpdate;
use Illuminate\Support\Facades\Log;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Jobs\RabbitMQJob as BaseJob;

/**
 * Custom RabbitMQ job handler for incoming ERP messages.
 * 
 * ERP sends raw JSON (not Laravel Job payload), so we need
 * a custom handler to parse and route these messages.
 */
class ErpIncomingJob extends BaseJob
{
    /**
     * Fire the job.
     */
    public function fire(): void
    {
        $rawBody = $this->getRawBody();
        
        Log::info('ERP incoming message received', ['body' => $rawBody]);

        try {
            $payload = json_decode($rawBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('ERP incoming: Invalid JSON', ['body' => $rawBody]);
                $this->delete();
                return;
            }

            // Route the message based on payload content
            if (isset($payload['user'])) {
                ProcessErpUserUpdate::dispatch($payload);
            }

            // TODO: Add more routing logic as ERP integration expands
            // e.g. if (isset($payload['company'])) { ProcessErpCompanyUpdate::dispatch($payload); }

            $this->delete();
        } catch (\Exception $e) {
            Log::error('ERP incoming: Error processing message', [
                'error' => $e->getMessage(),
                'body' => $rawBody,
            ]);

            // Release back to queue for retry
            $this->release(30);
        }
    }

    /**
     * Get the name of the queued job class.
     * Required stub for raw messages without 'job' key.
     */
    public function getName(): string
    {
        return 'ErpIncomingJob';
    }
}
