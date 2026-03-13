<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Прямой AMQP-публикатор для исходящих сообщений (Сайт → 1С).
 *
 * Публикует raw JSON напрямую в exchange site.events,
 * минуя Laravel Queue и пакет vyuldashev/laravel-queue-rabbitmq.
 * Это исключает создание delay-очередей и любых задержек.
 */
class ErpPublisher
{
    private ?AMQPStreamConnection $connection = null;

    /**
     * Опубликовать сообщение в site.events exchange.
     *
     * @param string $routingKey routing key (например, partner.created, order.created)
     * @param array $payload данные сообщения
     */
    public function publish(string $routingKey, array $payload): void
    {
        $channel = $this->getConnection()->channel();

        $message = new AMQPMessage(
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => $payload['message_id'] ?? null,
                'timestamp' => time(),
            ]
        );

        $channel->basic_publish($message, 'site.events', $routingKey);
        $channel->close();

        Log::info("ERP: {$routingKey} опубликован в site.events", [
            'routing_key' => $routingKey,
            'message_id' => $payload['message_id'] ?? null,
        ]);
    }

    private function getConnection(): AMQPStreamConnection
    {
        if ($this->connection === null || !$this->connection->isConnected()) {
            $this->connection = new AMQPStreamConnection(
                config('queue.connections.rabbitmq.hosts.0.host', 'rabbitmq'),
                (int) config('queue.connections.rabbitmq.hosts.0.port', 5672),
                config('queue.connections.rabbitmq.hosts.0.user', 'guest'),
                config('queue.connections.rabbitmq.hosts.0.password', 'guest'),
                config('queue.connections.rabbitmq.hosts.0.vhost', '/'),
            );
        }

        return $this->connection;
    }

    public function __destruct()
    {
        if ($this->connection?->isConnected()) {
            $this->connection->close();
        }
    }
}
