<?php

namespace Tests\Feature\Erp;

use App\Console\Commands\SetupRabbitMQTopology;
use Illuminate\Support\Facades\Artisan;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Маршрутизация printed_document.* через RabbitMQ (v16.1.0).
 *
 * Печатные формы едут в собственную очередь, а не в существующую erp_in.documents:
 * та обрабатывает реализации одним воркером, и первичная выгрузка форм за год
 * заблокировала бы их приём. Тест защищает и биндинг, и это разделение — exchange
 * `erp.events` об отсутствии подписчика не сигнализирует, и потерянные сообщения
 * выглядели бы как «1С ничего не прислала».
 *
 * Требует поднятый RabbitMQ; без брокера скипается.
 */
class PrintedDocumentTopologyIntegrationTest extends TestCase
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
    public function printed_document_queue_is_declared_with_its_own_routing_key(): void
    {
        $reflection = new \ReflectionClass(SetupRabbitMQTopology::class);
        $incoming = $reflection->getConstant('INCOMING_QUEUES');

        $this->assertArrayHasKey('erp_in.printed_documents', $incoming);
        $this->assertContains('printed_document.*', $incoming['erp_in.printed_documents']);

        // Очередь реализаций печатные формы не слушает и слушать не должна.
        $this->assertNotContains('printed_document.*', $incoming['erp_in.documents']);
    }

    #[Test]
    public function file_transfer_queue_is_declared(): void
    {
        // Без внутренней очереди `documents` StorePrintedDocumentFile ушёл бы
        // в несуществующую очередь и файл никогда не перенёсся бы.
        $reflection = new \ReflectionClass(SetupRabbitMQTopology::class);

        $this->assertContains('documents', $reflection->getConstant('INTERNAL_QUEUES'));
    }

    #[Test]
    public function printed_document_events_route_to_their_queue(): void
    {
        $channel = $this->connection->channel();

        $channel->queue_purge('erp_in.printed_documents');
        $channel->queue_purge('erp_in.documents');

        $payload = json_encode([
            'event' => 'printed_document.published',
            'message_id' => 'topology-test-pdoc-'.uniqid(),
            'uuid' => '3f2504e0-4f89-11d3-9a0c-0305e82c3301',
            'type_code' => 'tax_invoice',
            'date' => '2026-08-12',
            'file_url' => 's3://documents-exchange/2026/08/test.pdf',
        ]);

        $channel->basic_publish(
            new AMQPMessage($payload, ['content_type' => 'application/json']),
            'erp.events',
            'printed_document.published',
        );

        $msg = $this->awaitMessage($channel, 'erp_in.printed_documents');

        $this->assertNotNull($msg, 'printed_document.published должно попасть в erp_in.printed_documents');
        $this->assertSame($payload, $msg->getBody());

        $leak = $channel->basic_get('erp_in.documents', true);
        $this->assertNull($leak, 'erp_in.documents не должна получать printed_document.* события');

        $channel->close();
    }

    #[Test]
    public function shipment_events_still_route_to_documents_queue(): void
    {
        // Регрессия: новая очередь не должна перехватить реализации.
        $channel = $this->connection->channel();

        $channel->queue_purge('erp_in.documents');
        $channel->queue_purge('erp_in.printed_documents');

        $payload = json_encode([
            'event' => 'shipment.updated',
            'message_id' => 'topology-test-shipment-'.uniqid(),
            'uuid' => '550e8400-e29b-41d4-a716-446655440000',
        ]);

        $channel->basic_publish(
            new AMQPMessage($payload, ['content_type' => 'application/json']),
            'erp.events',
            'shipment.updated',
        );

        $this->assertNotNull(
            $this->awaitMessage($channel, 'erp_in.documents'),
            'shipment.updated должно попасть в erp_in.documents',
        );

        $this->assertNull(
            $channel->basic_get('erp_in.printed_documents', true),
            'erp_in.printed_documents не должна получать shipment.* события',
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
