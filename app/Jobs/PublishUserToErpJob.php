<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class PublishUserToErpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(public array $payload)
    {
        //
    }

    /**
     * Execute the job.
     * Публикует событие partner.created в очередь erp_out.partners (US-01 v2, Сайт → 1С).
     */
    public function handle(): void
    {
        try {
            Queue::connection('rabbitmq')->pushRaw(
                json_encode($this->payload, JSON_UNESCAPED_UNICODE),
                'erp_out.partners'
            );

            Log::info('partner.created опубликован в erp_out.partners', [
                'uuid' => $this->payload['uuid'] ?? null,
                'login' => $this->payload['login'] ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error('Не удалось опубликовать partner.created в ERP: ' . $e->getMessage(), [
                'payload' => $this->payload,
            ]);
            throw $e;
        }
    }
}
