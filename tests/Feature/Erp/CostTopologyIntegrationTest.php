<?php

namespace Tests\Feature\Erp;

use App\Console\Commands\SetupRabbitMQTopology;
use Illuminate\Support\Facades\Artisan;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Интеграционный тест маршрутизации cost.* через RabbitMQ (US-18, v15.13.0).
 *
 * Себестоимость намеренно едет в существующую `erp_in.prices`, а не в собственную
 * очередь: поток тот же по природе и объёму, что цены, а у очереди цен уже 12 воркеров.
 * Тест защищает биндинг `cost.*` — без него сообщение молча уходило бы в никуда,
 * потому что exchange `erp.events` не сигнализирует об отсутствии подписчика.
 *
 * Требует поднятый RabbitMQ; без брокера скипается.
 */
class CostTopologyIntegrationTest extends TestCase
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
    public function cost_routing_key_is_bound_to_prices_queue(): void
    {
        $reflection = new \ReflectionClass(SetupRabbitMQTopology::class);
        $incoming = $reflection->getConstant('INCOMING_QUEUES');

        $this->assertArrayHasKey('erp_in.prices', $incoming);
        $this->assertContains('cost.*', $incoming['erp_in.prices']);
    }

    #[Test]
    public function cost_events_route_to_erp_in_prices(): void
    {
        $channel = $this->connection->channel();

        $channel->queue_purge('erp_in.prices');
        $channel->queue_purge('erp_in.catalog');

        $payload = json_encode([
            'event' => 'cost.updated',
            'message_id' => 'topology-test-cost-'.uniqid(),
            'product_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'cost' => 8450.00,
            'currency_code' => 'RUB',
        ]);

        $channel->basic_publish(
            new AMQPMessage($payload, ['content_type' => 'application/json']),
            'erp.events',
            'cost.updated',
        );

        $msg = $this->awaitMessage($channel, 'erp_in.prices');

        $this->assertNotNull($msg, 'cost.updated должно попасть в erp_in.prices');
        $this->assertSame($payload, $msg->getBody());

        $leak = $channel->basic_get('erp_in.catalog', true);
        $this->assertNull($leak, 'erp_in.catalog не должна получать cost.* события');

        $channel->close();
    }

    #[Test]
    public function price_events_still_route_to_prices_queue(): void
    {
        // Регрессия: добавление cost.* не должно сломать существующий биндинг price.*.
        $channel = $this->connection->channel();

        $channel->queue_purge('erp_in.prices');

        $payload = json_encode([
            'event' => 'price.updated',
            'message_id' => 'topology-test-price-'.uniqid(),
            'product_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'price' => 12500.50,
        ]);

        $channel->basic_publish(
            new AMQPMessage($payload, ['content_type' => 'application/json']),
            'erp.events',
            'price.updated',
        );

        $msg = $this->awaitMessage($channel, 'erp_in.prices');

        $this->assertNotNull($msg, 'price.updated должно попасть в erp_in.prices');

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
