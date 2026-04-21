<?php

namespace App\Queue\Jobs;

use App\Models\ErpProcessedMessage;
use App\Services\Erp\Handlers\HandleExternalProductQuantityUpdated;
use Illuminate\Support\Facades\Log;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Jobs\RabbitMQJob as BaseJob;

/**
 * Consumer очереди external.remains_for_website.
 *
 * Формат envelope:
 *   {
 *     "service": "service-products",
 *     "uid": "<message uuid>",
 *     "event": {"name": "product.quantity.updated", "payload": {...}},
 *     "created_timestamp": 1776792661.84,
 *     "accepted_timestamp": 1776792661.86,
 *     "exclude_subscribers": ["epa-ut"]
 *   }
 *
 * Протокол — внешний ESB (не 1С↔Сайт), поэтому отделён от ErpIncomingJob.
 * Идемпотентность через erp_processed_messages с префиксом `external-remains:`
 * (чтобы UUID сообщения ESB не пересекался с message_id 1С).
 */
class ExternalRemainsJob extends BaseJob
{
    private const EVENT_HANDLERS = [
        'product.quantity.updated' => HandleExternalProductQuantityUpdated::class,
    ];

    private const IDEMPOTENCY_PREFIX = 'external-remains:';

    public function fire(): void
    {
        $rawBody = $this->getRawBody();

        try {
            $envelope = json_decode($rawBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('external.remains: невалидный JSON, сообщение удалено', [
                    'body' => mb_substr($rawBody, 0, 500),
                ]);
                $this->delete();

                return;
            }

            $eventName = $envelope['event']['name'] ?? null;
            $messageUid = $envelope['uid'] ?? null;

            if (! $eventName) {
                Log::warning('external.remains: в envelope отсутствует event.name, сообщение удалено', [
                    'envelope_uid' => $messageUid,
                ]);
                $this->delete();

                return;
            }

            $idempotencyKey = $messageUid ? self::IDEMPOTENCY_PREFIX.$messageUid : null;

            if ($idempotencyKey && ErpProcessedMessage::where('message_id', $idempotencyKey)->exists()) {
                Log::info('external.remains: сообщение уже обработано (идемпотентность)', [
                    'message_id' => $idempotencyKey,
                    'event' => $eventName,
                ]);
                $this->delete();

                return;
            }

            $handlerClass = self::EVENT_HANDLERS[$eventName] ?? null;

            if (! $handlerClass) {
                Log::warning('external.remains: неизвестный тип события, сообщение удалено', [
                    'event' => $eventName,
                    'envelope_uid' => $messageUid,
                ]);
                $this->delete();

                return;
            }

            app($handlerClass)->handle($envelope);

            if ($idempotencyKey) {
                ErpProcessedMessage::create([
                    'message_id' => $idempotencyKey,
                    'event' => $eventName,
                    'processed_at' => now(),
                ]);
            }

            $this->delete();
        } catch (\Throwable $e) {
            Log::error('external.remains: ошибка обработки сообщения', [
                'error' => $e->getMessage(),
                'body_preview' => mb_substr($rawBody, 0, 500),
            ]);

            $this->release(30);
        }
    }

    public function getName(): string
    {
        return 'ExternalRemainsJob';
    }
}
