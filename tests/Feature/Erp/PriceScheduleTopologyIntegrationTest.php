<?php

namespace Tests\Feature\Erp;

use Illuminate\Support\Facades\Artisan;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Интеграционный тест маршрутизации цен с датой вступления в силу (v16.13.0).
 *
 * price.cancelled — новое событие; отдельного биндинга не заводили, его везёт
 * существующий `price.*` в `erp_in.prices`, к тем же 12 воркерам цен. Тест
 * защищает это: exchange `erp.events` не сигнализирует об отсутствии
 * подписчика, и без биндинга отмена молча терялась бы, а отложенная цена
 * включалась бы в срок.
 *
 * Требует поднятый RabbitMQ; без брокера скипается.
 */
class PriceScheduleTopologyIntegrationTest extends TestCase
{
    private static bool $topologyApplied = false;

    private ?AMQPStreamConnection $connection = null;

    protected function setUp(): void
    {
        parent::setUp();

        $host = config('queue.connections.rabbitmq.hosts.0.host', 'rabbitmq');
        $port = (int) config('queue.connections.rabbitmq.hosts.0.port', 5672);
        $user = config('queue.connections.rabbitmq.hosts.0.user', 'guest');
        $pass = config('queue.connections.rabbitmq.hosts.0.password', 'guest');
        $vhost = config('queue.connections.rabbitmq.hosts.0.vhost', '/');

        try {
            $this->connection = new AMQPStreamConnection(
                $host, $port, $user, $pass, $vhost, false, 'AMQPLAIN', null, 'en_US', 3.0, 3.0,
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('RabbitMQ недоступен: '.$e->getMessage());
        }

        if (! self::$topologyApplied) {
            Artisan::call('rabbitmq:setup');
            self::$topologyApplied = true;
        }
    }

    protected function tearDown(): void
    {
        if ($this->connection) {
            try {
                $this->connection->close();
            } catch (\Throwable) {
                // ignore
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function price_cancelled_routes_to_erp_in_prices(): void
    {
        $this->assertRoutedToPrices('price.cancelled', [
            'event' => 'price.cancelled',
            'message_id' => 'topology-test-price-cancel-'.uniqid(),
            'document_uuid' => '0c2f4e10-9a1b-11f1-8948-b00eaec447ca',
            'product_uuids' => ['550e8400-e29b-41d4-a716-446655440000'],
        ]);
    }

    #[Test]
    public function dated_price_updated_routes_to_erp_in_prices(): void
    {
        $this->assertRoutedToPrices('price.updated', [
            'event' => 'price.updated',
            'message_id' => 'topology-test-price-dated-'.uniqid(),
            'product_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'price' => 2295,
            'effective_from' => '2026-09-28T00:00:00+03:00',
            'document_uuid' => '0c2f4e10-9a1b-11f1-8948-b00eaec447ca',
            'document_number' => 'УТ-00000123',
        ]);
    }

    private function assertRoutedToPrices(string $routingKey, array $payload): void
    {
        $channel = $this->connection->channel();

        $channel->queue_purge('erp_in.prices');
        $channel->queue_purge('erp_in.catalog');

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $channel->basic_publish(
            new AMQPMessage($body, ['content_type' => 'application/json']),
            'erp.events',
            $routingKey,
        );

        $msg = $this->awaitMessage($channel, 'erp_in.prices');

        $this->assertNotNull($msg, "{$routingKey} должно попасть в erp_in.prices");
        $this->assertSame($body, $msg->getBody());
        $this->assertNull($channel->basic_get('erp_in.catalog', true), "erp_in.catalog не должна получать {$routingKey}");

        $channel->close();
    }

    private function awaitMessage(\PhpAmqpLib\Channel\AMQPChannel $channel, string $queue): ?AMQPMessage
    {
        for ($i = 0; $i < 40; $i++) {
            $msg = $channel->basic_get($queue, true);
            if ($msg !== null) {
                return $msg;
            }
            usleep(50_000);
        }

        return null;
    }
}
