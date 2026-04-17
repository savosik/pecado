<?php

namespace App\Services\Erp;

use App\Models\ErpBusMessage;
use Illuminate\Support\Facades\Log;

/**
 * Сервис логирования сообщений ERP-шины.
 *
 * Записывает входящие/исходящие сообщения RabbitMQ в БД
 * для отображения в админке. Управляется переменной
 * окружения ERP_BUS_LOGGING_ENABLED.
 */
class ErpBusLogger
{
    /**
     * Проверить, включено ли логирование.
     */
    public static function isEnabled(): bool
    {
        return (bool) config('erp.bus_logging_enabled', false);
    }

    /**
     * Залогировать входящее сообщение (1С → Сайт).
     */
    public static function logIncoming(
        string $event,
        array $payload,
        string $status = 'success',
        ?string $errorMessage = null,
        ?string $routingKey = null,
    ): void {
        if (! self::isEnabled()) {
            return;
        }

        self::log('incoming', $event, $payload, $status, $errorMessage, $routingKey);
    }

    /**
     * Залогировать исходящее сообщение (Сайт → 1С).
     */
    public static function logOutgoing(
        string $event,
        array $payload,
        string $status = 'success',
        ?string $errorMessage = null,
        ?string $routingKey = null,
    ): void {
        if (! self::isEnabled()) {
            return;
        }

        self::log('outgoing', $event, $payload, $status, $errorMessage, $routingKey);
    }

    /**
     * Записать сообщение в БД.
     */
    private static function log(
        string $direction,
        string $event,
        array $payload,
        string $status,
        ?string $errorMessage,
        ?string $routingKey,
    ): void {
        try {
            ErpBusMessage::create([
                'direction' => $direction,
                'routing_key' => $routingKey,
                'event' => $event,
                'message_id' => $payload['message_id'] ?? null,
                'payload' => $payload,
                'status' => $status,
                'error_message' => $errorMessage,
            ]);
        } catch (\Throwable $e) {
            Log::error('ErpBusLogger: не удалось записать сообщение', [
                'error' => $e->getMessage(),
                'event' => $event,
            ]);
        }
    }
}
