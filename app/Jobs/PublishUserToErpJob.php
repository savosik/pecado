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

class PublishUserToErpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 30;

    public function __construct(public array $payload)
    {
        $this->queue = 'erp_publish';
    }

    /**
     * Execute the job.
     * Публикует событие partner.created в очередь erp_out.partners (US-01 v2, Сайт → 1С).
     */
    public function handle(): void
    {
        $event = $this->payload['event'] ?? 'partner.created';

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
            ErpBusLogger::logOutgoing($event, $this->payload, 'failed', implode('; ', $validation['errors']), 'erp_out.partners');

            return;
        }

        try {
            Queue::connection('rabbitmq')->pushRaw(
                json_encode($this->payload, JSON_UNESCAPED_UNICODE),
                'erp_out.partners'
            );

            Log::info('partner.created опубликован в erp_out.partners', [
                'uuid' => $this->payload['uuid'] ?? null,
                'login' => $this->payload['login'] ?? null,
            ]);

            ErpBusLogger::logOutgoing($event, $this->payload, 'success', null, 'erp_out.partners');
        } catch (\Exception $e) {
            Log::error('Не удалось опубликовать partner.created в ERP: '.$e->getMessage(), [
                'payload' => $this->payload,
            ]);
            ErpBusLogger::logOutgoing($event, $this->payload, 'failed', $e->getMessage(), 'erp_out.partners');
            throw $e;
        }
    }
}
