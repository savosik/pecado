<?php

namespace App\Jobs;

use App\Services\ErpPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PublishReturnToErpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(public array $payload)
    {
        //
    }

    /**
     * Публикует return.created напрямую через AMQP в site.events exchange.
     */
    public function handle(ErpPublisher $publisher): void
    {
        try {
            $publisher->publish('return.created', $this->payload);
        } catch (\Exception $e) {
            Log::error('Не удалось опубликовать return.created в ERP: ' . $e->getMessage(), [
                'payload' => $this->payload,
            ]);
            throw $e;
        }
    }
}
