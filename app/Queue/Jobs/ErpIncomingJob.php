<?php

namespace App\Queue\Jobs;

use App\Models\ErpProcessedMessage;
use App\Services\Erp\ErpBusLogger;
use App\Services\Erp\ErpHandlerOutcome;
use App\Services\Erp\ErpMessageValidator;
use App\Services\Erp\Exceptions\ErpUnprocessableMessageException;
use App\Services\Erp\Handlers\HandleBalanceUpdated;
use App\Services\Erp\Handlers\HandleCategoryCreated;
use App\Services\Erp\Handlers\HandleCategoryUpdated;
use App\Services\Erp\Handlers\HandleContractorCreated;
use App\Services\Erp\Handlers\HandleContractorDeleted;
use App\Services\Erp\Handlers\HandleContractorUpdated;
use App\Services\Erp\Handlers\HandleExchangeRateUpdated;
use App\Services\Erp\Handlers\HandleIndividualPricesReady;
use App\Services\Erp\Handlers\HandleOrderCreated;
use App\Services\Erp\Handlers\HandleOrderDeleted;
use App\Services\Erp\Handlers\HandleOrderUpdated;
use App\Services\Erp\Handlers\HandlePartnerCreated;
use App\Services\Erp\Handlers\HandlePartnerDeleted;
use App\Services\Erp\Handlers\HandlePartnerUpdated;
use App\Services\Erp\Handlers\HandlePaymentCreated;
use App\Services\Erp\Handlers\HandlePaymentDeleted;
use App\Services\Erp\Handlers\HandlePaymentUpdated;
use App\Services\Erp\Handlers\HandlePriceUpdated;
use App\Services\Erp\Handlers\HandleProductCreated;
use App\Services\Erp\Handlers\HandleProductUpdated;
use App\Services\Erp\Handlers\HandlePromotionCreated;
use App\Services\Erp\Handlers\HandlePromotionDeleted;
use App\Services\Erp\Handlers\HandlePromotionUpdated;
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
        // US-02: Партнёры
        'partner.created' => HandlePartnerCreated::class,
        'partner.updated' => HandlePartnerUpdated::class,
        'partner.deleted' => HandlePartnerDeleted::class,
        // US-03: Базовые цены
        'price.updated' => HandlePriceUpdated::class,
        // US-05: Курсы валют
        'exchange_rate.updated' => HandleExchangeRateUpdated::class,
        // US-06: Остатки
        'stock.updated' => HandleStockUpdated::class,
        // US-07: Контрагенты
        'contractor.created' => HandleContractorCreated::class,
        'contractor.updated' => HandleContractorUpdated::class,
        'contractor.deleted' => HandleContractorDeleted::class,
        // US-08: Заказы
        'order.created' => HandleOrderCreated::class,
        'order.updated' => HandleOrderUpdated::class,
        'order.deleted' => HandleOrderDeleted::class,
        // US-09: Возвраты (отложено)
        'return.updated' => HandleReturnUpdated::class,
        'return.deleted' => HandleReturnDeleted::class,
        // US-10: Реализации
        'shipment.created' => HandleShipmentCreated::class,
        'shipment.updated' => HandleShipmentUpdated::class,
        'shipment.deleted' => HandleShipmentDeleted::class,
        // US-11: Баланс
        'balance.updated' => HandleBalanceUpdated::class,
        // US-14: Индивидуальные цены (v7)
        'individual_prices.ready' => HandleIndividualPricesReady::class,
        // US-15: Каталог
        'category.created' => HandleCategoryCreated::class,
        'category.updated' => HandleCategoryUpdated::class,
        'product.created' => HandleProductCreated::class,
        'product.updated' => HandleProductUpdated::class,
        // US-16: Промо-флаги товаров
        'promotion.created' => HandlePromotionCreated::class,
        'promotion.updated' => HandlePromotionUpdated::class,
        'promotion.deleted' => HandlePromotionDeleted::class,
        // US-17: Платежи
        'payment.created' => HandlePaymentCreated::class,
        'payment.updated' => HandlePaymentUpdated::class,
        'payment.deleted' => HandlePaymentDeleted::class,
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

            if (! $event) {
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

            if (! $handlerClass) {
                Log::warning('ERP incoming: неизвестный тип события', [
                    'event' => $event,
                    'payload' => $payload,
                ]);
                $this->delete();

                return;
            }

            // Валидация payload по JSON Schema (AsyncAPI)
            /** @var ErpMessageValidator $validator */
            $validator = app(ErpMessageValidator::class);
            $validation = $validator->validate($event, $payload);

            if (! $validation['valid']) {
                Log::warning('ERP incoming: payload не соответствует JSON Schema', [
                    'event' => $event,
                    'message_id' => $messageId,
                    'errors' => $validation['errors'],
                    'payload' => $payload,
                ]);

                // Сохраняем ошибку в БД для отображения в админке
                $validator->logValidationError($event, 'incoming', $validation['errors'], $payload);

                // Логируем в шину ERP (failed)
                ErpBusLogger::logIncoming(
                    $event,
                    $payload,
                    'failed',
                    implode('; ', $validation['errors']),
                    $this->getQueue(),
                );

                // Удаляем невалидное сообщение — повторная обработка не поможет
                $this->delete();

                return;
            }

            // v15.4: сбрасываем исход перед обработкой — воркер долгоживущий,
            // иначе пометка от предыдущего сообщения протечёт в это.
            /** @var ErpHandlerOutcome $outcome */
            $outcome = app(ErpHandlerOutcome::class);
            $outcome->reset();

            /** @var object $handler */
            $handler = app($handlerClass);
            $handler->handle($payload);

            // Записываем message_id для идемпотентности
            if ($messageId) {
                $this->markAsProcessed($messageId, $event);
            }

            // Логируем обработку в шину ERP. Обычно 'success'; 'recovered' —
            // если handler достроил сущность, потерянную на стороне 1С.
            ErpBusLogger::logIncoming(
                $event,
                $payload,
                $outcome->status(),
                $outcome->message(),
                $this->getQueue(),
            );

            $this->delete();
        } catch (ErpUnprocessableMessageException $e) {
            // v15.4: сообщение валидно, но обработать его нельзя, и повтор не поможет.
            // Не возвращаем в очередь — помечаем failed, чтобы ошибка была видна
            // в админке, и удаляем.
            Log::warning('ERP incoming: сообщение невозможно обработать', [
                'event' => $payload['event'] ?? 'unknown',
                'message_id' => $payload['message_id'] ?? null,
                'reason' => $e->getMessage(),
            ]);

            ErpBusLogger::logIncoming(
                $payload['event'] ?? 'unknown',
                $payload ?? [],
                'failed',
                $e->getMessage(),
                $this->getQueue(),
            );

            $this->delete();
        } catch (\Throwable $e) {
            Log::error('ERP incoming: ошибка обработки сообщения', [
                'error' => $e->getMessage(),
                'body' => $rawBody,
            ]);

            // Логируем ошибку в шину ERP
            ErpBusLogger::logIncoming(
                $payload['event'] ?? 'unknown',
                $payload ?? [],
                'failed',
                $e->getMessage(),
                $this->getQueue(),
            );

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
