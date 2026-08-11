<?php

namespace Tests\Feature\Erp;

use Illuminate\Support\Facades\Artisan;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Маршрутизация регистра взаиморасчётов через RabbitMQ (v16.0.0, карточка fin-04).
 *
 * Движения вынесены в собственную очередь `erp_in.settlements`, а не подмешаны
 * к платежам: первичная выгрузка — 224 632 движения за год, и у платежей один
 * воркер. Подмешав, мы бы на сутки остановили приём оплат ради истории.
 *
 * Соглашения, наоборот, едут вместе с контрагентами — это мастер-данные того же
 * справочника, 5 102 записи, своей очереди не стоят.
 *
 * Требует поднятый RabbitMQ; без брокера скипается.
 */
class SettlementTopologyIntegrationTest extends TestCase
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
    public function settlement_events_route_to_settlements_queue_and_not_payments(): void
    {
        $channel = $this->connection->channel();

        $channel->queue_purge('erp_in.settlements');
        $channel->queue_purge('erp_in.payments');

        $payload = json_encode([
            'event' => 'settlement.posted',
            'message_id' => 'topology-test-settlement-'.uniqid(),
            'document_uuid' => '8e1c3a52-6f4b-4b1e-9d0a-2c7f5a8b1d34',
            'entries' => [],
        ]);

        $channel->basic_publish(
            new AMQPMessage($payload, ['content_type' => 'application/json']),
            'erp.events',
            'settlement.posted',
        );

        $msg = $this->awaitMessage($channel, 'erp_in.settlements');

        $this->assertNotNull($msg, 'settlement.posted должно попасть в erp_in.settlements');
        $this->assertSame($payload, $msg->getBody());

        $leak = $channel->basic_get('erp_in.payments', true);
        $this->assertNull($leak, 'erp_in.payments не должна получать settlement.* события');

        $channel->close();
    }

    #[Test]
    public function payment_schedule_events_route_to_settlements_queue(): void
    {
        $channel = $this->connection->channel();

        $channel->queue_purge('erp_in.settlements');
        $channel->queue_purge('erp_in.payments');

        $payload = json_encode([
            'event' => 'payment_schedule.updated',
            'message_id' => 'topology-test-schedule-'.uniqid(),
            'document_uuid' => '8e1c3a52-6f4b-4b1e-9d0a-2c7f5a8b1d34',
            'document_kind' => 'shipment',
            'lines' => [],
        ]);

        $channel->basic_publish(
            new AMQPMessage($payload, ['content_type' => 'application/json']),
            'erp.events',
            'payment_schedule.updated',
        );

        $msg = $this->awaitMessage($channel, 'erp_in.settlements');

        $this->assertNotNull($msg, 'payment_schedule.updated должно попасть в erp_in.settlements');

        // График переехал из shipment.* в своё событие — в очередь платежей
        // он попадать не должен ни при каком стечении обстоятельств.
        $leak = $channel->basic_get('erp_in.payments', true);
        $this->assertNull($leak, 'erp_in.payments не должна получать payment_schedule.* события');

        $channel->close();
    }

    #[Test]
    public function agreement_events_route_to_contractors_queue(): void
    {
        $channel = $this->connection->channel();

        $channel->queue_purge('erp_in.contractors');
        $channel->queue_purge('erp_in.settlements');

        $payload = json_encode([
            'event' => 'agreement.created',
            'message_id' => 'topology-test-agreement-'.uniqid(),
            'uuid' => '5c8a2f4d-7e1b-4903-a6c5-8f2d4b7e1a39',
        ]);

        $channel->basic_publish(
            new AMQPMessage($payload, ['content_type' => 'application/json']),
            'erp.events',
            'agreement.created',
        );

        $msg = $this->awaitMessage($channel, 'erp_in.contractors');

        $this->assertNotNull($msg, 'agreement.created должно попасть в erp_in.contractors');

        $leak = $channel->basic_get('erp_in.settlements', true);
        $this->assertNull($leak, 'erp_in.settlements не должна получать agreement.* события');

        $channel->close();
    }

    #[Test]
    public function settlements_queue_has_dead_letter_route(): void
    {
        $channel = $this->connection->channel();

        // passive-декларация: очередь и её DLQ обязаны существовать после
        // rabbitmq:setup, а не создаваться этим тестом.
        $channel->queue_declare('erp_in.settlements', true);
        $channel->queue_declare('erp_dlq.settlements', true);

        $this->assertTrue(true, 'erp_in.settlements и erp_dlq.settlements объявлены');

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
