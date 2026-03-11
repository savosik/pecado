<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Wire\AMQPTable;

class SetupRabbitMQTopology extends Command
{
    protected $signature = 'rabbitmq:setup';

    protected $description = 'Создание топологии RabbitMQ (exchanges, queues, bindings, DLQ)';

    /**
     * Входящие очереди (1С → Сайт) с routing keys.
     */
    private const INCOMING_QUEUES = [
        'erp_in.partners'  => ['partner.*'],
        'erp_in.prices'    => ['price.*', 'discount.*', 'exchange_rate.*'],
        'erp_in.stock'     => ['stock.*'],
        'erp_in.orders'    => ['order.*'],
        'erp_in.returns'   => ['return.*'],
        'erp_in.documents' => ['shipment.*'],
        'erp_in.balance'   => ['balance.*'],
        'erp_in.segments'  => ['product_segment.*', 'partner_segment.*'], // US-11, US-12
        'erp_in.catalog'   => ['category.*', 'product.*'],               // US-13
    ];

    /**
     * Исходящие очереди (Сайт → 1С) с routing keys.
     */
    private const OUTGOING_QUEUES = [
        'erp_out.orders'   => ['order.created'],
        'erp_out.returns'  => ['return.created'],
        'erp_out.partners' => ['partner.created'], // US-01 v2: Сайт → 1С
    ];

    public function handle(): int
    {
        $host = config('queue.connections.rabbitmq.hosts.0.host', 'rabbitmq');
        $port = (int) config('queue.connections.rabbitmq.hosts.0.port', 5672);
        $user = config('queue.connections.rabbitmq.hosts.0.user', 'guest');
        $password = config('queue.connections.rabbitmq.hosts.0.password', 'guest');
        $vhost = config('queue.connections.rabbitmq.hosts.0.vhost', '/');

        $this->info('Подключение к RabbitMQ...');

        try {
            $connection = new AMQPStreamConnection($host, $port, $user, $password, $vhost);
            $channel = $connection->channel();

            // 1. Dead Letter Exchange
            $this->info('Создание Dead Letter Exchange: erp.dlx');
            $channel->exchange_declare('erp.dlx', AMQPExchangeType::TOPIC, false, true, false);

            // 2. Dead Letter Queues
            foreach (self::INCOMING_QUEUES as $queue => $routingKeys) {
                $dlqName = str_replace('erp_in.', 'erp_dlq.', $queue);
                $this->info("Создание DLQ: {$dlqName}");
                $channel->queue_declare($dlqName, false, true, false, false);

                foreach ($routingKeys as $key) {
                    $channel->queue_bind($dlqName, 'erp.dlx', $key);
                }
            }

            // 3. Входящий Exchange (1С → Сайт)
            $this->info('Создание Exchange: erp.events (topic, durable)');
            $channel->exchange_declare('erp.events', AMQPExchangeType::TOPIC, false, true, false);

            // 4. Входящие очереди с DLQ
            foreach (self::INCOMING_QUEUES as $queue => $routingKeys) {
                $dlqName = str_replace('erp_in.', 'erp_dlq.', $queue);

                $arguments = new AMQPTable([
                    'x-dead-letter-exchange' => 'erp.dlx',
                    'x-dead-letter-routing-key' => $routingKeys[0],
                ]);

                $this->info("Создание очереди: {$queue}");
                $channel->queue_declare($queue, false, true, false, false, false, $arguments);

                foreach ($routingKeys as $key) {
                    $this->info("  Binding: {$key} → {$queue}");
                    $channel->queue_bind($queue, 'erp.events', $key);
                }
            }

            // 5. Исходящий Exchange (Сайт → 1С)
            $this->info('Создание Exchange: site.events (topic, durable)');
            $channel->exchange_declare('site.events', AMQPExchangeType::TOPIC, false, true, false);

            // 6. Исходящие очереди
            foreach (self::OUTGOING_QUEUES as $queue => $routingKeys) {
                $this->info("Создание очереди: {$queue}");
                $channel->queue_declare($queue, false, true, false, false);

                foreach ($routingKeys as $key) {
                    $this->info("  Binding: {$key} → {$queue}");
                    $channel->queue_bind($queue, 'site.events', $key);
                }
            }

            $channel->close();
            $connection->close();

            $this->info('');
            $this->info('✅ Топология RabbitMQ успешно создана!');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Ошибка: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }
}
