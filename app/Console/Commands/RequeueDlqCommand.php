<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class RequeueDlqCommand extends Command
{
    protected $signature = 'erp:requeue-dlq
        {queue : имя DLQ-очереди (например, erp_dlq.catalog)}
        {--limit=0 : максимум сообщений за прогон, 0 = все}
        {--exchange=erp.events : exchange для повторной публикации}
        {--dry-run : только показать, без публикации и ack}';

    protected $description = 'Переотправляет сообщения из DLQ обратно в основной exchange (erp.events) со сбросом x-death/laravel.attempts';

    public function handle(): int
    {
        $dlqName = $this->argument('queue');
        $limit = (int) $this->option('limit');
        $exchange = (string) $this->option('exchange');
        $dryRun = (bool) $this->option('dry-run');

        if (! str_starts_with($dlqName, 'erp_dlq.')) {
            $this->error("Очередь '{$dlqName}' не похожа на DLQ (ожидался префикс erp_dlq.).");

            return self::FAILURE;
        }

        $host = config('queue.connections.rabbitmq.hosts.0.host', 'rabbitmq');
        $port = (int) config('queue.connections.rabbitmq.hosts.0.port', 5672);
        $user = config('queue.connections.rabbitmq.hosts.0.user', 'guest');
        $password = config('queue.connections.rabbitmq.hosts.0.password', 'guest');
        $vhost = config('queue.connections.rabbitmq.hosts.0.vhost', '/');

        try {
            $connection = new AMQPStreamConnection($host, $port, $user, $password, $vhost);
        } catch (\Throwable $e) {
            $this->error('RabbitMQ connect error: '.$e->getMessage());

            return self::FAILURE;
        }

        $channel = $connection->channel();
        $processed = 0;
        $failed = 0;

        $this->info("Источник: {$dlqName}, цель: exchange={$exchange}".($dryRun ? ' [DRY-RUN]' : ''));

        while (true) {
            if ($limit > 0 && $processed >= $limit) {
                break;
            }

            $msg = $channel->basic_get($dlqName);
            if ($msg === null) {
                break;
            }

            try {
                $routingKey = $this->resolveRoutingKey($msg);

                if ($routingKey === null) {
                    $this->warn('Сообщение без routing key — пропускаю (nack без requeue в DLQ невозможен — оставляю в очереди).');
                    $channel->basic_reject($msg->getDeliveryTag(), true);
                    $failed++;

                    continue;
                }

                $newMessage = new AMQPMessage($msg->getBody(), [
                    'content_type' => $msg->get_properties()['content_type'] ?? 'application/json',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'application_headers' => $this->cleanHeaders($msg),
                ]);

                if ($dryRun) {
                    $this->line("  [dry-run] {$routingKey} (".strlen($msg->getBody()).' bytes)');
                    $channel->basic_reject($msg->getDeliveryTag(), true);
                } else {
                    $channel->basic_publish($newMessage, $exchange, $routingKey);
                    $channel->basic_ack($msg->getDeliveryTag());
                }

                $processed++;

                if ($processed % 50 === 0) {
                    $this->info("  переотправлено: {$processed}");
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->error('Ошибка при переотправке: '.$e->getMessage());
                $channel->basic_reject($msg->getDeliveryTag(), true);
            }
        }

        $channel->close();
        $connection->close();

        $this->info("Готово. Переотправлено: {$processed}, ошибок/пропущено: {$failed}".($dryRun ? ' [DRY-RUN]' : ''));

        return self::SUCCESS;
    }

    /**
     * Routing key в ERP-протоколе всегда совпадает с полем `event` в payload
     * (например, "product.created"). При публикации в DLX routing key теряется
     * (заменяется на DLX-routing-key из x-dead-letter-routing-key), поэтому
     * восстанавливаем его из самого payload.
     */
    private function resolveRoutingKey(AMQPMessage $msg): ?string
    {
        $payload = json_decode($msg->getBody(), true);

        if (is_array($payload) && isset($payload['event']) && is_string($payload['event']) && $payload['event'] !== '') {
            return $payload['event'];
        }

        $deliveryRoutingKey = $msg->get('routing_key');
        if (is_string($deliveryRoutingKey) && $deliveryRoutingKey !== '' && ! str_contains($deliveryRoutingKey, '*')) {
            return $deliveryRoutingKey;
        }

        return null;
    }

    /**
     * Чистим x-death и laravel.attempts, чтобы Laravel-воркер не зарежектил
     * сообщение сразу как уже сделавшее 3 попытки.
     */
    private function cleanHeaders(AMQPMessage $msg): AMQPTable
    {
        $properties = $msg->get_properties();
        $headers = $properties['application_headers'] ?? null;

        if ($headers instanceof AMQPTable) {
            $headerArr = $headers->getNativeData();
        } elseif (is_array($headers)) {
            $headerArr = $headers;
        } else {
            $headerArr = [];
        }

        unset(
            $headerArr['x-death'],
            $headerArr['x-first-death-exchange'],
            $headerArr['x-first-death-queue'],
            $headerArr['x-first-death-reason'],
            $headerArr['x-first-death-routing-keys'],
            $headerArr['x-last-death-exchange'],
            $headerArr['x-last-death-queue'],
            $headerArr['x-last-death-reason'],
            $headerArr['x-last-death-routing-keys'],
            $headerArr['laravel'],
        );

        return new AMQPTable($headerArr);
    }
}
