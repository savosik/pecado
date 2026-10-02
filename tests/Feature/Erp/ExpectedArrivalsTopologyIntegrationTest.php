<?php

namespace Tests\Feature\Erp;

use App\Console\Commands\SetupRabbitMQTopology;
use Illuminate\Support\Facades\Artisan;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Интеграционный тест маршрутизации `product.expected_arrivals.*` через RabbitMQ
 * (v16.16.0, топик №16 Agent Hub).
 *
 * У ожидаемых поступлений своя очередь `erp_in.expected_arrivals`. Ключ начинается
 * с `product.`, как у событий каталога, поэтому главный риск — что снимки уедут
 * в `erp_in.catalog` (привязка `product.*`) или не уедут никуда: exchange
 * `erp.events` не сигнализирует об отсутствии подписчика. В topic-обменнике `*` —
 * ровно одно слово, а в ключе их три; тест закрепляет это на живом брокере.
 *
 * Требует поднятый RabbitMQ; без брокера скипается.
 */
class ExpectedArrivalsTopologyIntegrationTest extends TestCase
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
    public function expected_arrivals_queue_is_declared_with_its_routing_key(): void
    {
        $reflection = new \ReflectionClass(SetupRabbitMQTopology::class);
        $incoming = $reflection->getConstant('INCOMING_QUEUES');

        $this->assertArrayHasKey('erp_in.expected_arrivals', $incoming);
        $this->assertSame(['product.expected_arrivals.*'], $incoming['erp_in.expected_arrivals']);
    }

    #[Test]
    public function expected_arrivals_route_to_their_own_queue_and_not_to_catalog(): void
    {
        $channel = $this->connection->channel();

        $channel->queue_purge('erp_in.expected_arrivals');
        $channel->queue_purge('erp_in.catalog');

        $payload = json_encode([
            'event' => 'product.expected_arrivals.updated',
            'message_id' => 'topology-test-arrivals-'.uniqid(),
            'product_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'calculated_at' => '2026-10-02T09:15:00+03:00',
            'warehouses' => [],
        ]);

        $channel->basic_publish(
            new AMQPMessage($payload, ['content_type' => 'application/json']),
            'erp.events',
            'product.expected_arrivals.updated',
        );

        $msg = $this->awaitMessage($channel, 'erp_in.expected_arrivals');

        $this->assertNotNull($msg, 'product.expected_arrivals.updated должно попасть в erp_in.expected_arrivals');
        $this->assertSame($payload, $msg->getBody());

        $this->assertNull(
            $channel->basic_get('erp_in.catalog', true),
            'erp_in.catalog не должна получать product.expected_arrivals.* — привязка product.* ловит ровно одно слово',
        );

        $channel->close();
    }

    #[Test]
    public function expected_arrivals_have_their_own_dead_letter_queue(): void
    {
        $channel = $this->connection->channel();

        // passive = true: очередь обязана существовать, иначе брокер закроет канал с 404.
        [$queue] = $channel->queue_declare('erp_dlq.expected_arrivals', true);
        $this->assertSame('erp_dlq.expected_arrivals', $queue);

        $channel->queue_purge('erp_dlq.expected_arrivals');
        $channel->basic_publish(
            new AMQPMessage('{"dead":true}', ['content_type' => 'application/json']),
            'erp.dlx',
            'product.expected_arrivals.updated',
        );

        $this->assertNotNull(
            $this->awaitMessage($channel, 'erp_dlq.expected_arrivals'),
            'Недоставленное сообщение должно попадать в erp_dlq.expected_arrivals',
        );

        $channel->close();
    }

    #[Test]
    public function supervisor_runs_a_single_consumer_for_the_queue(): void
    {
        $config = (string) file_get_contents(base_path('docker/supervisor/conf.d/worker.conf'));

        $this->assertSame(1, preg_match(
            '/\[program:erp-expected-arrivals-consumer\](.*?)(?=\n\[program:|\z)/s',
            $config,
            $match,
        ), 'В worker.conf нет программы erp-expected-arrivals-consumer');

        $this->assertStringContainsString('queue:work rabbitmq-erp-incoming --queue=erp_in.expected_arrivals', $match[1]);
        $this->assertSame(1, preg_match('/^numprocs=1$/m', $match[1]), 'Очередь ожиданий обслуживает один процесс');
    }

    private function awaitMessage(AMQPChannel $channel, string $queue): ?AMQPMessage
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
