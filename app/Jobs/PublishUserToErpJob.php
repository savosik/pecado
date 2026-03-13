<?php

namespace App\Jobs;

use App\Services\ErpPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PublishUserToErpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $backoff = 30;

    public function __construct(public array $payload)
    {
        //
    }

    /**
     * Публикует partner.created напрямую через AMQP в site.events exchange.
     * Без задержек, без delay-очередей.
     */
    public function handle(ErpPublisher $publisher): void
    {
        try {
            $publisher->publish('partner.created', $this->payload);
        } catch (\Exception $e) {
            Log::error('Не удалось опубликовать partner.created в ERP: ' . $e->getMessage(), [
                'payload' => $this->payload,
            ]);
            throw $e;
        }
    }
}
