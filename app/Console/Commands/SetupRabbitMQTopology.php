<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Wire\AMQPTable;

class SetupRabbitMQTopology extends Command
{
    protected $signature = 'rabbitmq:setup';

    protected $description = 'Создание топологии RabbitMQ (exchanges, queues, bindings, DLQ, shovels)';

    /**
     * Внешние (не-ERP) fanout-обменники и очереди-потребители.
     *
     * Shovel тянет сообщения из внешних систем (например, московского ESB) →
     * публикует в fanout-обменник → сообщение копируется во все привязанные очереди.
     */
    private const EXTERNAL_FANOUTS = [
        'external.remains' => [
            'external.remains_for_website',
            'external.remains_for_erp',
        ],
    ];

    /**
     * Входящие очереди (1С → Сайт) с routing keys.
     */
    private const INCOMING_QUEUES = [
        'erp_in.partners' => ['partner.*', 'contractor.*'],
        'erp_in.prices' => ['price.*', 'exchange_rate.*', 'individual_prices.*'],
        'erp_in.stock' => ['stock.*'],
        'erp_in.orders' => ['order.*'],
        'erp_in.returns' => ['return.*'],
        'erp_in.documents' => ['shipment.*'],
        'erp_in.balance' => ['balance.*'],
        'erp_in.catalog' => ['category.*', 'product.*'],
    ];

    /**
     * Исходящие очереди (Сайт → 1С) с routing keys.
     */
    private const OUTGOING_QUEUES = [
        'erp_out.orders' => ['order.created'],
        'erp_out.returns' => ['return.created'],
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

            // 7. Внешние fanout-обменники и их очереди
            foreach (self::EXTERNAL_FANOUTS as $exchange => $queues) {
                $this->info("Создание fanout exchange: {$exchange}");
                $channel->exchange_declare($exchange, AMQPExchangeType::FANOUT, false, true, false);

                foreach ($queues as $queue) {
                    $this->info("  Создание очереди: {$queue}");
                    $channel->queue_declare($queue, false, true, false, false);
                    $channel->queue_bind($queue, $exchange);
                    $this->info("  Binding: {$queue} → {$exchange}");
                }
            }

            $channel->close();
            $connection->close();

            // 8. Dynamic shovel с московского ESB
            $this->setupMoscowShovel();

            $this->info('');
            $this->info('✅ Топология RabbitMQ успешно создана!');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Ошибка: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }

    /**
     * Настройка dynamic shovel-а, тянущего внешние остатки с московского ESB
     * и публикующего их в fanout `external.remains`.
     *
     * Параметры передаются через Management HTTP API (PUT /api/parameters/shovel/{vhost}/{name}).
     * Если `MOSCOW_ESB_AMQP_URI` не задан — shovel не создаётся (локальный dev / CI).
     */
    private function setupMoscowShovel(): void
    {
        $cfg = config('erp.moscow_shovel');
        $srcUri = $cfg['src_uri'] ?? null;

        $this->info('');
        $this->info('Настройка shovel-а с московского ESB...');

        if (empty($srcUri)) {
            $this->warn('  MOSCOW_ESB_AMQP_URI не задан — shovel пропущен.');

            return;
        }

        $managementHost = config('queue.connections.rabbitmq.hosts.0.host', 'rabbitmq');
        $managementPort = config('queue.connections.rabbitmq.management.port', 15672);
        $managementUser = config('queue.connections.rabbitmq.management.user', 'guest');
        $managementPass = config('queue.connections.rabbitmq.management.password', 'guest');
        $vhost = config('queue.connections.rabbitmq.hosts.0.vhost', '/');

        $payload = [
            'value' => [
                'src-protocol' => 'amqp091',
                'src-uri' => $srcUri,
                'src-queue' => $cfg['src_queue'],
                'src-prefetch-count' => $cfg['prefetch_count'],
                'dest-protocol' => 'amqp091',
                'dest-uri' => 'amqp://',
                'dest-exchange' => $cfg['dest_exchange'],
                'dest-exchange-key' => '',
                'ack-mode' => 'on-confirm',
                'add-forward-headers' => false,
                'delete-after' => 'never',
                'reconnect-delay' => $cfg['reconnect_delay'],
            ],
        ];

        $url = sprintf(
            'http://%s:%s/api/parameters/shovel/%s/%s',
            $managementHost,
            $managementPort,
            rawurlencode($vhost),
            rawurlencode($cfg['name']),
        );

        try {
            $response = Http::withBasicAuth($managementUser, $managementPass)
                ->timeout(10)
                ->asJson()
                ->put($url, $payload);

            if ($response->successful()) {
                $this->info("  ✅ Shovel '{$cfg['name']}' создан/обновлён: {$cfg['src_queue']} → {$cfg['dest_exchange']}");

                return;
            }

            if ($response->status() === 404) {
                $this->error('  ❌ RabbitMQ Management API вернул 404 — плагин rabbitmq_shovel_management не включён.');
                $this->warn('     Включите плагины: docker compose exec rabbitmq rabbitmq-plugins enable rabbitmq_shovel rabbitmq_shovel_management');

                return;
            }

            $this->error("  ❌ Не удалось создать shovel (HTTP {$response->status()}): {$response->body()}");
        } catch (\Exception $e) {
            $this->error("  ❌ Ошибка подключения к Management API: {$e->getMessage()}");
        }
    }
}
