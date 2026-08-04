<?php

namespace Tests\Feature\Erp;

use Illuminate\Support\Facades\Artisan;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Интеграционный тест топологии заказов из чужой 1С (fanout `external.orders_from_andrey`).
 *
 * Проверяет, что после `php artisan rabbitmq:setup` (v15.9.1, 2026-08-04):
 *  - очередь `external.orders_from_andrey_for_website` УДАЛЕНА — зеркало для
 *    отладки потребителя не имеет и без TTL растёт неограниченно;
 *  - очередь `external.orders_from_andrey_for_erp` сохранена и по-прежнему получает
 *    копию сообщений из fanout (путь «ESB Andrey → 1С Pecado» не сломан).
 *
 * Требует поднятый RabbitMQ. Если брокер недоступен — тест skip-ается.
 */
class AndreyOrdersTopologyIntegrationTest extends TestCase
{
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

        // Пересоздаём очередь-зеркало перед каждым прогоном, чтобы убедиться,
        // что именно rabbitmq:setup её удаляет (а не «повезло, что её не было»).
        $channel = $this->connection->channel();
        $channel->exchange_declare('external.orders_from_andrey', 'fanout', false, true, false);
        $channel->queue_declare('external.orders_from_andrey_for_website', false, true, false, false);
        $channel->queue_bind('external.orders_from_andrey_for_website', 'external.orders_from_andrey');
        $channel->close();

        Artisan::call('rabbitmq:setup');
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
    public function website_mirror_queue_is_deleted_after_setup(): void
    {
        $channel = $this->connection->channel();

        // Passive declare несуществующей очереди → 404 (AMQPProtocolChannelException).
        $this->expectException(AMQPProtocolChannelException::class);

        try {
            $channel->queue_declare('external.orders_from_andrey_for_website', true);
        } finally {
            // Канал закрыт брокером после 404 — отдельный канал не нужен.
        }
    }

    #[Test]
    public function erp_orders_queue_still_receives_fanout_messages(): void
    {
        $channel = $this->connection->channel();

        // Очередь 1С должна существовать (passive declare не бросает исключение).
        $channel->queue_declare('external.orders_from_andrey_for_erp', true);
        $channel->queue_purge('external.orders_from_andrey_for_erp');

        $payload = json_encode([
            'created_timestamp' => microtime(true),
            'event' => [
                'name' => 'invoice.updated',
                'payload' => ['uid' => 'topology-test-andrey-'.uniqid()],
            ],
        ]);

        $channel->basic_publish(
            new AMQPMessage($payload, ['content_type' => 'application/json']),
            'external.orders_from_andrey',
            '',
        );

        $msg = $this->awaitMessage($channel, 'external.orders_from_andrey_for_erp');

        $this->assertNotNull($msg, 'external.orders_from_andrey_for_erp должна получать копию из fanout external.orders_from_andrey');
        $this->assertSame($payload, $msg->getBody());

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
