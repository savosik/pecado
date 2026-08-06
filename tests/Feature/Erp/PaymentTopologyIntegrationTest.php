<?php

namespace Tests\Feature\Erp;

use Illuminate\Support\Facades\Artisan;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Интеграционный тест маршрутизации payment.* через RabbitMQ (US-17).
 *
 * Платежи вынесены в собственную очередь `erp_in.payments`, а не подмешаны в
 * `erp_in.documents`, потому что первичная выгрузка платежей за всю историю
 * заблокировала бы приём реализаций: у документов один воркер. Тест защищает
 * именно это разделение — оба направления утечки.
 *
 * Требует поднятый RabbitMQ; без брокера скипается.
 */
class PaymentTopologyIntegrationTest extends TestCase
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
    public function payment_events_route_to_erp_in_payments_and_not_documents(): void
    {
        $channel = $this->connection->channel();

        $channel->queue_purge('erp_in.payments');
        $channel->queue_purge('erp_in.documents');

        $payload = json_encode([
            'event' => 'payment.created',
            'message_id' => 'topology-test-pay-'.uniqid(),
            'uuid' => '550e8400-e29b-41d4-a716-446655440077',
            'number' => '29УТ-002488',
            'date' => '2026-07-30T23:59:59+03:00',
            'direction' => 'in',
            'contractor_uuid' => '550e8400-e29b-41d4-a716-446655440043',
            'amount' => 2325.20,
            'currency_code' => 'RUB',
        ]);

        $channel->basic_publish(
            new AMQPMessage($payload, ['content_type' => 'application/json']),
            'erp.events',
            'payment.created',
        );

        $msg = $this->awaitMessage($channel, 'erp_in.payments');

        $this->assertNotNull($msg, 'payment.created должно попасть в erp_in.payments');
        $this->assertSame($payload, $msg->getBody());

        $leak = $channel->basic_get('erp_in.documents', true);
        $this->assertNull($leak, 'erp_in.documents не должна получать payment.* события');

        $channel->close();
    }

    #[Test]
    public function shipment_events_do_not_leak_into_payments_queue(): void
    {
        $channel = $this->connection->channel();

        $channel->queue_purge('erp_in.payments');
        $channel->queue_purge('erp_in.documents');

        $payload = json_encode([
            'event' => 'shipment.created',
            'message_id' => 'topology-test-ship-'.uniqid(),
            'uuid' => '550e8400-e29b-41d4-a716-446655440078',
            'number' => '29УТ-003413',
            'contractor_uuid' => '550e8400-e29b-41d4-a716-446655440043',
        ]);

        $channel->basic_publish(
            new AMQPMessage($payload, ['content_type' => 'application/json']),
            'erp.events',
            'shipment.created',
        );

        $msg = $this->awaitMessage($channel, 'erp_in.documents');
        $this->assertNotNull($msg, 'shipment.created должно попасть в erp_in.documents');

        $leak = $channel->basic_get('erp_in.payments', true);
        $this->assertNull($leak, 'erp_in.payments не должна получать shipment.* события');

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
