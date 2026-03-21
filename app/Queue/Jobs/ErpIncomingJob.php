<?php

namespace App\Queue\Jobs;

use App\Models\ErpProcessedMessage;
use App\Services\Erp\Handlers\HandleBalanceUpdated;
use App\Services\Erp\Handlers\HandleCategoryCreated;
use App\Services\Erp\Handlers\HandleCategoryUpdated;
use App\Services\Erp\Handlers\HandleDiscountCreated;
use App\Services\Erp\Handlers\HandleDiscountDeleted;
use App\Services\Erp\Handlers\HandleDiscountUpdated;
use App\Services\Erp\Handlers\HandleContractorCreated;
use App\Services\Erp\Handlers\HandleExchangeRateUpdated;
use App\Services\Erp\Handlers\HandleOrderCreated;
use App\Services\Erp\Handlers\HandleOrderDeleted;
use App\Services\Erp\Handlers\HandleOrderUpdated;
use App\Services\Erp\Handlers\HandlePartnerCreated;
use App\Services\Erp\Handlers\HandlePartnerDeleted;
use App\Services\Erp\Handlers\HandlePartnerSegmentCreated;
use App\Services\Erp\Handlers\HandlePartnerSegmentDeleted;
use App\Services\Erp\Handlers\HandlePartnerSegmentUpdated;
use App\Services\Erp\Handlers\HandlePriceUpdated;
use App\Services\Erp\Handlers\HandleProductCreated;
use App\Services\Erp\Handlers\HandleProductSegmentCreated;
use App\Services\Erp\Handlers\HandleProductSegmentDeleted;
use App\Services\Erp\Handlers\HandleProductSegmentUpdated;
use App\Services\Erp\Handlers\HandleProductUpdated;
use App\Services\Erp\Handlers\HandleReturnDeleted;
use App\Services\Erp\Handlers\HandleReturnUpdated;
use App\Services\Erp\Handlers\HandleShipmentCreated;
use App\Services\Erp\Handlers\HandleShipmentDeleted;
use App\Services\Erp\Handlers\HandleShipmentUpdated;
use App\Services\Erp\Handlers\HandleStockUpdated;
use Illuminate\Support\Facades\Log;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Jobs\RabbitMQJob as BaseJob;

/**
 * Custom RabbitMQ job handler for incoming ERP messages.
 *
 * ERP sends raw JSON (not Laravel Job payload), so we need
 * a custom handler to parse and route these messages by `event` field.
 */
class ErpIncomingJob extends BaseJob
{
    /**
     * Карта маршрутизации событий на обработчики.
     *
     * partner.created: v2 — исходящее (Сайт → 1С через erp_out.partners).
     * v4 — также входящее (1С → Сайт через erp_in.partners) для выгрузки партнёров.
     */
    private const EVENT_HANDLERS = [
        // US-01
        'partner.created'           => HandlePartnerCreated::class, // v4: выгрузка партнёров 1С → Сайт
        'partner.deleted'           => HandlePartnerDeleted::class,
        // US-02
        'price.updated'             => HandlePriceUpdated::class,
        // US-03
        'discount.created'          => HandleDiscountCreated::class,
        'discount.updated'          => HandleDiscountUpdated::class,
        'discount.deleted'          => HandleDiscountDeleted::class,
        // US-04
        'exchange_rate.updated'     => HandleExchangeRateUpdated::class,
        // US-05
        'stock.updated'             => HandleStockUpdated::class,
        // US-06
        'contractor.created'        => HandleContractorCreated::class, // v4: выгрузка контрагентов 1С → Сайт
        // US-07
        'order.created'             => HandleOrderCreated::class, // v3: заказ от менеджера (1С → Сайт)
        'order.updated'             => HandleOrderUpdated::class,
        'order.deleted'             => HandleOrderDeleted::class,
        // US-08 (отложено, обработчики сохранены)
        'return.updated'            => HandleReturnUpdated::class,
        'return.deleted'            => HandleReturnDeleted::class,
        // US-09
        'shipment.created'          => HandleShipmentCreated::class,
        'shipment.updated'          => HandleShipmentUpdated::class,
        'shipment.deleted'          => HandleShipmentDeleted::class,
        // US-10
        'balance.updated'           => HandleBalanceUpdated::class,
        // US-11: сегменты номенклатуры
        'product_segment.created'   => HandleProductSegmentCreated::class,
        'product_segment.updated'   => HandleProductSegmentUpdated::class,
        'product_segment.deleted'   => HandleProductSegmentDeleted::class,
        // US-12: сегменты партнёров
        'partner_segment.created'   => HandlePartnerSegmentCreated::class,
        'partner_segment.updated'   => HandlePartnerSegmentUpdated::class,
        'partner_segment.deleted'   => HandlePartnerSegmentDeleted::class,
        // US-13: каталог (категории и товары)
        'category.created'          => HandleCategoryCreated::class,
        'category.updated'          => HandleCategoryUpdated::class,
        'product.created'           => HandleProductCreated::class,
        'product.updated'           => HandleProductUpdated::class,
    ];


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
                Log::error('ERP incoming: невалидный JSON', ['body' => $rawBody]);
                $this->delete();

                return;
            }

            $event = $payload['event'] ?? null;
            $messageId = $payload['message_id'] ?? null;

            if (!$event) {
                Log::warning('ERP incoming: отсутствует поле event', ['payload' => $payload]);
                $this->delete();

                return;
            }

            // Проверка идемпотентности
            if ($messageId && $this->isAlreadyProcessed($messageId)) {
                Log::info('ERP incoming: сообщение уже обработано (идемпотентность)', [
                    'message_id' => $messageId,
                    'event' => $event,
                ]);
                $this->delete();

                return;
            }

            // Маршрутизация по типу события
            $handlerClass = self::EVENT_HANDLERS[$event] ?? null;

            if (!$handlerClass) {
                Log::warning('ERP incoming: неизвестный тип события', [
                    'event' => $event,
                    'payload' => $payload,
                ]);
                $this->delete();

                return;
            }

            /** @var object $handler */
            $handler = app($handlerClass);
            $handler->handle($payload);

            // Записываем message_id для идемпотентности
            if ($messageId) {
                $this->markAsProcessed($messageId, $event);
            }

            $this->delete();
        } catch (\Exception $e) {
            Log::error('ERP incoming: ошибка обработки сообщения', [
                'error' => $e->getMessage(),
                'body' => $rawBody,
            ]);

            // Release back to queue for retry
            $this->release(30);
        }
    }

    /**
     * Проверить, было ли сообщение уже обработано.
     */
    private function isAlreadyProcessed(string $messageId): bool
    {
        return ErpProcessedMessage::where('message_id', $messageId)->exists();
    }

    /**
     * Отметить сообщение как обработанное.
     */
    private function markAsProcessed(string $messageId, string $event): void
    {
        ErpProcessedMessage::create([
            'message_id' => $messageId,
            'event' => $event,
            'processed_at' => now(),
        ]);
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
