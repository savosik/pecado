<?php

namespace Tests\Feature\Erp;

use Illuminate\Support\Facades\Artisan;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Интеграционный тест маршрутизации contractor.* / partner.* через RabbitMQ.
 *
 * Проверяет, что после `php artisan rabbitmq:setup`:
 *  - сообщения `contractor.*` уходят в `erp_in.contractors` и НЕ попадают в `erp_in.partners`;
 *  - сообщения `partner.*` уходят в `erp_in.partners` и НЕ попадают в `erp_in.contractors`.
 *
 * Требует поднятый RabbitMQ. Если брокер недоступен — тест skip-ается, чтобы не падать в окружениях без него.
 */
class ContractorTopologyIntegrationTest extends TestCase
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
                $host,
                $port,
                $user,
                $pass,
                $vhost,
                false,
                'AMQPLAIN',
                null,
                'en_US',
                3.0,
                3.0,
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
    public function contractor_events_route_to_erp_in_contractors_and_not_partners(): void
    {
        $channel = $this->connection->channel();

        $channel->queue_purge('erp_in.contractors');
        $channel->queue_purge('erp_in.partners');

        $payload = json_encode([
            'event' => 'contractor.created',
            'message_id' => 'topology-test-c-'.uniqid(),
            'timestamp' => now()->toIso8601String(),
            'uuid' => '550e8400-e29b-41d4-a716-446655440042',
            'partner_uuid' => '550e8400-e29b-41d4-a716-446655440043',
            'tax_id' => '7700000000',
            'name' => 'Topology Test Contractor',
        ]);

        $channel->basic_publish(
            new AMQPMessage($payload, ['content_type' => 'application/json']),
            'erp.events',
            'contractor.created',
        );

        $msg = $this->awaitMessage($channel, 'erp_in.contractors');

        $this->assertNotNull($msg, 'contractor.created должно попасть в erp_in.contractors');
        $this->assertSame($payload, $msg->getBody());

        $leak = $channel->basic_get('erp_in.partners', true);
        $this->assertNull($leak, 'erp_in.partners не должна получать contractor.* события (старый binding снят)');

        $channel->close();
    }

    #[Test]
    public function partner_events_route_to_erp_in_partners_and_not_contractors(): void
    {
        $channel = $this->connection->channel();

        $channel->queue_purge('erp_in.contractors');
        $channel->queue_purge('erp_in.partners');

        $payload = json_encode([
            'event' => 'partner.created',
            'message_id' => 'topology-test-p-'.uniqid(),
            'timestamp' => now()->toIso8601String(),
            'uuid' => '550e8400-e29b-41d4-a716-446655440099',
            'name' => 'Topology Test Partner',
            'email' => 'topology-test-partner@example.com',
            'is_active' => true,
        ]);

        $channel->basic_publish(
            new AMQPMessage($payload, ['content_type' => 'application/json']),
            'erp.events',
            'partner.created',
        );

        $msg = $this->awaitMessage($channel, 'erp_in.partners');

        $this->assertNotNull($msg, 'partner.created должно попасть в erp_in.partners');
        $this->assertSame($payload, $msg->getBody());

        $leak = $channel->basic_get('erp_in.contractors', true);
        $this->assertNull($leak, 'erp_in.contractors не должна получать partner.* события');

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
