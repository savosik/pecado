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
            // external.remains_for_website отключена 2026-06-11 (v15.2): сайт
            // больше не потребляет внешние остатки. Очередь удаляется в DELETED_QUEUES.
            'external.remains_for_erp',
        ],
        'external.orders_from_andrey' => [
            // external.orders_from_andrey_for_website выведена из топологии 2026-05-18:
            // зеркало для отладки, потребителя нет. Очередь удаляется в DELETED_QUEUES.
            'external.orders_from_andrey_for_erp',
        ],
    ];

    /**
     * Очереди, которые нужно удалить при первом запуске после миграции топологии.
     *
     * v15.2 (2026-06-11): сайт перестал потреблять внешние остатки —
     * очередь external.remains_for_website отвязывается от fanout `external.remains`
     * и удаляется (queue_delete заодно снимает её binding). 1С продолжает получать
     * остатки через external.remains_for_erp. queue_delete идемпотентен:
     * если очереди уже нет (CI / свежий dev), 404 ловится и не роняет setup.
     *
     * v15.9.1 (2026-08-04): сюда же добавлена external.orders_from_andrey_for_website.
     * Из `EXTERNAL_FANOUTS` её убрали ещё 2026-05-18, но в этот список не внесли —
     * рассчитывали на разовое ручное `rabbitmqctl delete_queue`. На prod очередь
     * пережила ту чистку, осталась привязанной к fanout и к 2026-08-04 накопила
     * 5003 сообщения (296 МБ): потребителя нет, а policy `external-remains-ttl`
     * ловит только `^external\.remains_for_.*$`, так что TTL на неё не действует.
     *
     * @var array<int, string>
     */
    private const DELETED_QUEUES = [
        'external.remains_for_website',
        'external.orders_from_andrey_for_website',
    ];

    /**
     * Входящие очереди (1С → Сайт) с routing keys.
     */
    private const INCOMING_QUEUES = [
        'erp_in.partners' => ['partner.*'],
        // v16.0.0: соглашения с клиентами едут вместе с контрагентами — это
        // мастер-данные того же справочника, 5 102 записи, своей очереди не стоят.
        'erp_in.contractors' => ['contractor.*', 'agreement.*'],
        'erp_in.prices' => ['price.*', 'cost.*', 'exchange_rate.*', 'individual_prices.*'],
        'erp_in.stock' => ['stock.*'],
        'erp_in.orders' => ['order.*'],
        'erp_in.returns' => ['return.*'],
        'erp_in.documents' => ['shipment.*'],
        'erp_in.payments' => ['payment.*'],
        // US-20: расходные ордера. Отдельно от erp_in.documents намеренно — у очереди
        // реализаций один воркер, и первоначальная выгрузка ордеров за всю историю
        // заблокировала бы приём реализаций, платежей и балансов.
        'erp_in.warehouse' => ['goods_issue.*'],
        'erp_in.balance' => ['balance.*'],
        // v16.0.0: регистр взаиморасчётов. Отдельно от erp_in.payments намеренно:
        // первичная выгрузка — 224 632 движения за год, и она не должна
        // заблокировать приём платежей, балансов и реализаций.
        'erp_in.settlements' => ['settlement.*', 'payment_schedule.*'],
        // v16.1.0: печатные формы документов. Отдельно от erp_in.documents
        // намеренно — та обрабатывает реализации одним воркером, и первичная
        // выгрузка печатных форм за год заблокировала бы их приём.
        'erp_in.printed_documents' => ['printed_document.*'],
        'erp_in.catalog' => ['category.*', 'product.*'],
        'erp_in.promotions' => ['promotion.*'],
    ];

    /**
     * Устаревшие bindings, которые надо снять при первом запуске после миграций.
     *
     * v13.5 (2026-04-25): contractor.* выделены из erp_in.partners в erp_in.contractors.
     * queue_unbind идемпотентен — если binding уже снят, повтор не падает.
     *
     * @var array<int, array{queue: string, exchange: string, key: string}>
     */
    private const STALE_BINDINGS = [
        ['queue' => 'erp_in.partners', 'exchange' => 'erp.events', 'key' => 'contractor.*'],
        ['queue' => 'erp_dlq.partners', 'exchange' => 'erp.dlx', 'key' => 'contractor.*'],
    ];

    /**
     * Исходящие очереди (Сайт → 1С) с routing keys.
     */
    private const OUTGOING_QUEUES = [
        'erp_out.orders' => ['order.created'],
        'erp_out.returns' => ['return.created'],
        'erp_out.partners' => ['partner.created'], // US-01 v2: Сайт → 1С
        'erp_out.contractors' => ['contractor.created'], // US-07 v13.2: Сайт → 1С
    ];

    /**
     * Внутренние Laravel-очереди для job-обёрток (без exchange/binding).
     * Сюда диспатчатся ShouldQueue-классы; consumer — supervisor-программы.
     *
     * Декларация делает топологию детерминированной после `rabbitmq:setup`:
     * очереди существуют сразу, не дожидаясь первого dispatch.
     */
    private const INTERNAL_QUEUES = [
        'erp_publish',   // Publish*ToErpJob (Contractor/User/Order/Return)
        'catalog-media', // DownloadProductMediaJob
        'documents',     // StorePrintedDocumentFile (v16.1.0)
        'default',       // safety-net для job-ов без явного onQueue()
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

            // 6.5. Снятие устаревших bindings (миграции топологии)
            foreach (self::STALE_BINDINGS as $b) {
                try {
                    $channel->queue_unbind($b['queue'], $b['exchange'], $b['key']);
                    $this->info("  Снят устаревший binding: {$b['key']} ✗ {$b['queue']}");
                } catch (\Exception $e) {
                    // 404 / not_found — binding уже снят, это нормально (идемпотентность)
                }
            }

            // 6.6. Внутренние Laravel-очереди (job-обёртки)
            foreach (self::INTERNAL_QUEUES as $queue) {
                $this->info("Создание внутренней очереди: {$queue}");
                $channel->queue_declare($queue, false, true, false, false);
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

            // 7.5. Удаление осиротевших очередей (миграция топологии)
            foreach (self::DELETED_QUEUES as $queue) {
                $this->deleteQueue($connection, $queue);
            }

            $channel->close();
            $connection->close();

            // 8. Policies (TTL и прочие runtime-свойства очередей)
            $this->setupPolicies();

            // 9. Dynamic shovel-ы с внешних ESB
            $this->setupShovel('moscow', config('erp.moscow_shovel'));
            $this->setupShovel('andrey', config('erp.andrey_shovel'));

            $this->info('');
            $this->info('✅ Топология RabbitMQ успешно создана!');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Ошибка: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }

    /**
     * Удаление осиротевшей очереди (вместе с её bindings).
     *
     * Используется отдельный канал: queue_delete на несуществующей очереди бросает
     * 404 и закрывает канал. Чтобы это не уронило основной канал в окружениях,
     * где очереди никогда не было (CI / свежий dev), изолируем операцию.
     */
    private function deleteQueue(AMQPStreamConnection $connection, string $queue): void
    {
        $channel = $connection->channel();

        try {
            $channel->queue_delete($queue);
            $this->info("  🗑 Удалена осиротевшая очередь: {$queue}");
        } catch (\Exception $e) {
            $this->info("  Очередь {$queue} отсутствует — пропуск (идемпотентность)");
        } finally {
            try {
                $channel->close();
            } catch (\Throwable) {
                // канал уже закрыт брокером после 404 — это нормально
            }
        }
    }

    /**
     * Регистрация RabbitMQ policies через Management API.
     *
     * Policies применяются к очередям/exchanges по regex-паттерну и задают
     * runtime-свойства (TTL, max-length, HA, и т.д.), которые нельзя изменить
     * через queue_declare на уже существующей очереди.
     */
    private function setupPolicies(): void
    {
        $this->info('');
        $this->info('Настройка policies...');

        $ttlMs = (int) config('erp.external_remains_ttl_ms', 3 * 24 * 60 * 60 * 1000);

        $policies = [
            'external-remains-ttl' => [
                'pattern' => '^external\.remains_for_.*$',
                'apply-to' => 'queues',
                'definition' => [
                    'message-ttl' => $ttlMs,
                ],
                'priority' => 0,
            ],
        ];

        foreach ($policies as $name => $body) {
            $this->putPolicy($name, $body);
        }
    }

    private function putPolicy(string $name, array $body): void
    {
        $host = config('queue.connections.rabbitmq.hosts.0.host', 'rabbitmq');
        $port = config('queue.connections.rabbitmq.management.port', 15672);
        $user = config('queue.connections.rabbitmq.management.user', 'guest');
        $pass = config('queue.connections.rabbitmq.management.password', 'guest');
        $vhost = config('queue.connections.rabbitmq.hosts.0.vhost', '/');

        $url = sprintf(
            'http://%s:%s/api/policies/%s/%s',
            $host,
            $port,
            rawurlencode($vhost),
            rawurlencode($name),
        );

        try {
            $response = Http::withBasicAuth($user, $pass)
                ->timeout(10)
                ->asJson()
                ->put($url, $body);

            if ($response->successful()) {
                $this->info("  ✅ Policy '{$name}': pattern={$body['pattern']}, definition=".json_encode($body['definition']));

                return;
            }

            $this->error("  ❌ Policy '{$name}' не применена (HTTP {$response->status()}): {$response->body()}");
        } catch (\Exception $e) {
            $this->error("  ❌ Ошибка Management API: {$e->getMessage()}");
        }
    }

    /**
     * Настройка dynamic shovel-а, тянущего сообщения с внешнего ESB
     * и публикующего их в локальный fanout-обменник.
     *
     * Параметры передаются через Management HTTP API (PUT /api/parameters/shovel/{vhost}/{name}).
     * Если `src_uri` пуст — shovel не создаётся (локальный dev / CI без доступа к ESB).
     *
     * @param  string  $label  человекочитаемое имя источника для логов (`moscow`, `andrey`)
     * @param  array|null  $cfg  конфигурация из `config/erp.php` (см. `moscow_shovel`, `andrey_shovel`)
     */
    private function setupShovel(string $label, ?array $cfg): void
    {
        $this->info('');
        $this->info("Настройка shovel-а '{$label}'...");

        if (empty($cfg) || empty($cfg['src_uri'])) {
            $envHint = strtoupper($label).'_ESB_AMQP_URI';
            $this->warn("  {$envHint} не задан — shovel '{$label}' пропущен.");

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
                'src-uri' => $cfg['src_uri'],
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
