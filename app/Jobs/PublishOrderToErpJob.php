<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class PublishOrderToErpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    /**
     * Create a new job instance.
     */
    public function __construct(public array $payload)
    {
        //
    }

    /**
     * Публикует order.created в очередь erp_out.orders (Сайт → 1С).
     */
    public function handle(): void
    {
        try {
            Queue::connection('rabbitmq')->pushRaw(
                json_encode($this->payload, JSON_UNESCAPED_UNICODE),
                'erp_out.orders'
            );

            Log::info('order.created опубликован в erp_out.orders', [
                'order_id' => $this->payload['uuid'] ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error('Не удалось опубликовать order.created в ERP: ' . $e->getMessage(), [
                'payload' => $this->payload,
            ]);
            throw $e;
        }
    }
}
