<?php

namespace App\Jobs;

use App\Services\Erp\ErpBusLogger;
use App\Services\Erp\ErpMessageValidator;
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

    public function __construct(public array $payload)
    {
        $this->queue = 'erp_publish';
    }

    /**
     * Публикует order.created в очередь erp_out.orders (Сайт → 1С).
     */
    public function handle(): void
    {
        $event = $this->payload['event'] ?? 'order.created';

        // Валидация исходящего payload по JSON Schema перед отправкой
        /** @var ErpMessageValidator $validator */
        $validator = app(ErpMessageValidator::class);
        $validation = $validator->validateOutbound($event, $this->payload);

        if (! $validation['valid']) {
            Log::warning("Исходящий {$event} payload не соответствует JSON Schema, сообщение не отправлено", [
                'errors' => $validation['errors'],
                'uuid' => $this->payload['uuid'] ?? null,
            ]);
            $validator->logValidationError($event, 'outgoing', $validation['errors'], $this->payload);
            ErpBusLogger::logOutgoing($event, $this->payload, 'failed', implode('; ', $validation['errors']), 'erp_out.orders');

            return;
        }

        try {
            Queue::connection('rabbitmq')->pushRaw(
                json_encode($this->payload, JSON_UNESCAPED_UNICODE),
                'erp_out.orders'
            );

            Log::info('order.created опубликован в erp_out.orders', [
                'order_id' => $this->payload['uuid'] ?? null,
            ]);

            ErpBusLogger::logOutgoing($event, $this->payload, 'success', null, 'erp_out.orders');
        } catch (\Exception $e) {
            Log::error('Не удалось опубликовать order.created в ERP: '.$e->getMessage(), [
                'payload' => $this->payload,
            ]);
            ErpBusLogger::logOutgoing($event, $this->payload, 'failed', $e->getMessage(), 'erp_out.orders');
            throw $e;
        }
    }
}
