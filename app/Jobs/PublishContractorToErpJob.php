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

/**
 * US-07: Публикация контрагента в erp_out.contractors (Сайт → 1С).
 *
 * Используется и для contractor.created (v13.2), и для contractor.updated (v13.X) —
 * конкретное событие определяется payload['event'].
 */
class PublishContractorToErpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 30;

    private const QUEUE_NAME = 'erp_out.contractors';

    public function __construct(public array $payload)
    {
        $this->queue = 'erp_publish';
    }

    public function handle(): void
    {
        $event = $this->payload['event'] ?? 'contractor.created';

        /** @var ErpMessageValidator $validator */
        $validator = app(ErpMessageValidator::class);
        $validation = $validator->validateOutbound($event, $this->payload);

        if (! $validation['valid']) {
            Log::warning("Исходящий {$event} payload не соответствует JSON Schema, сообщение не отправлено", [
                'errors' => $validation['errors'],
                'uuid' => $this->payload['uuid'] ?? null,
                'tax_id' => $this->payload['tax_id'] ?? null,
            ]);
            $validator->logValidationError($event, 'outgoing', $validation['errors'], $this->payload);
            ErpBusLogger::logOutgoing($event, $this->payload, 'failed', implode('; ', $validation['errors']), self::QUEUE_NAME);

            return;
        }

        try {
            Queue::connection('rabbitmq')->pushRaw(
                json_encode($this->payload, JSON_UNESCAPED_UNICODE),
                self::QUEUE_NAME
            );

            Log::info("{$event} опубликован в ".self::QUEUE_NAME, [
                'uuid' => $this->payload['uuid'] ?? null,
                'partner_uuid' => $this->payload['partner_uuid'] ?? null,
                'tax_id' => $this->payload['tax_id'] ?? null,
            ]);

            ErpBusLogger::logOutgoing($event, $this->payload, 'success', null, self::QUEUE_NAME);
        } catch (\Exception $e) {
            Log::error("Не удалось опубликовать {$event} в ERP: ".$e->getMessage(), [
                'payload' => $this->payload,
            ]);
            ErpBusLogger::logOutgoing($event, $this->payload, 'failed', $e->getMessage(), self::QUEUE_NAME);
            throw $e;
        }
    }
}
