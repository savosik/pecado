<?php

namespace Tests\Feature\Erp;

use App\Console\Commands\SetupRabbitMQTopology;
use Illuminate\Support\Facades\Artisan;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Интеграционный тест маршрутизации goods_issue.* через RabbitMQ (US-20, v15.15.0).
 *
 * Расходные ордера намеренно едут в собственную очередь `erp_in.warehouse`, а не
 * в `erp_in.documents` к реализациям: у той один воркер, и первоначальная выгрузка
 * ордеров за всю историю заблокировала бы приём реализаций, платежей и балансов.
 *
 * Тест защищает биндинг: без него сообщение молча уходило бы в никуда — exchange
 * `erp.events` об отсутствии подписчика не сигнализирует.
 *
 * Требует поднятый RabbitMQ; без брокера скипается.
 */
class GoodsIssueTopologyIntegrationTest extends TestCase
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
    public function goods_issue_routing_key_is_bound_to_warehouse_queue(): void
    {
        $reflection = new \ReflectionClass(SetupRabbitMQTopology::class);
        $incoming = $reflection->getConstant('INCOMING_QUEUES');

        $this->assertArrayHasKey('erp_in.warehouse', $incoming);
        $this->assertContains('goods_issue.*', $incoming['erp_in.warehouse']);
    }

    #[Test]
    public function goods_issue_events_route_to_erp_in_warehouse(): void
    {
        $channel = $this->connection->channel();

        $channel->queue_purge('erp_in.warehouse');
        $channel->queue_purge('erp_in.documents');

        $payload = json_encode([
            'event' => 'goods_issue.created',
            'message_id' => 'topology-test-gi-'.uniqid(),
            'uuid' => '7f3d9c10-4b21-4e8a-9c55-1a2b3c4d5e6f',
            'number' => 'УТ-00009419',
            'date' => '2026-07-08T13:25:55+03:00',
            'status' => 'to_pick',
            'items' => [
                ['product_uuid' => '550e8400-e29b-41d4-a716-446655440000', 'quantity' => 15],
            ],
        ]);

        $channel->basic_publish(
            new AMQPMessage($payload, ['content_type' => 'application/json']),
            'erp.events',
            'goods_issue.created',
        );

        $msg = $this->awaitMessage($channel, 'erp_in.warehouse');

        $this->assertNotNull($msg, 'goods_issue.created должно попасть в erp_in.warehouse');
        $this->assertSame($payload, $msg->getBody());

        $leak = $channel->basic_get('erp_in.documents', true);
        $this->assertNull($leak, 'erp_in.documents не должна получать goods_issue.* события');

        $channel->close();
    }

    #[Test]
    public function goods_issue_updated_and_deleted_use_the_same_queue(): void
    {
        $channel = $this->connection->channel();

        $channel->queue_purge('erp_in.warehouse');

        foreach (['goods_issue.updated', 'goods_issue.deleted'] as $routingKey) {
            $channel->basic_publish(
                new AMQPMessage(json_encode(['event' => $routingKey]), ['content_type' => 'application/json']),
                'erp.events',
                $routingKey,
            );

            $this->assertNotNull(
                $this->awaitMessage($channel, 'erp_in.warehouse'),
                $routingKey.' должно попасть в erp_in.warehouse',
            );
        }

        $channel->close();
    }

    #[Test]
    public function shipment_events_still_route_to_documents_queue(): void
    {
        // Регрессия: новая очередь не должна перехватывать поток реализаций.
        $channel = $this->connection->channel();

        $channel->queue_purge('erp_in.documents');
        $channel->queue_purge('erp_in.warehouse');

        $channel->basic_publish(
            new AMQPMessage(
                json_encode(['event' => 'shipment.created', 'message_id' => 'topology-test-shipment-'.uniqid()]),
                ['content_type' => 'application/json'],
            ),
            'erp.events',
            'shipment.created',
        );

        $this->assertNotNull(
            $this->awaitMessage($channel, 'erp_in.documents'),
            'shipment.created должно попасть в erp_in.documents',
        );

        $this->assertNull(
            $channel->basic_get('erp_in.warehouse', true),
            'erp_in.warehouse не должна получать shipment.* события',
        );

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
