<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ConsumeErpUpdates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'erp:consume';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consume incoming ERP updates from RabbitMQ';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $connection = new \PhpAmqpLib\Connection\AMQPStreamConnection(
            \Illuminate\Support\Facades\Config::get('queue.connections.rabbitmq.hosts.0.host'),
            \Illuminate\Support\Facades\Config::get('queue.connections.rabbitmq.hosts.0.port'),
            \Illuminate\Support\Facades\Config::get('queue.connections.rabbitmq.hosts.0.user'),
            \Illuminate\Support\Facades\Config::get('queue.connections.rabbitmq.hosts.0.password'),
            \Illuminate\Support\Facades\Config::get('queue.connections.rabbitmq.hosts.0.vhost')
        );

        $channel = $connection->channel();
        
        $queue = 'erp_incoming';
        $channel->queue_declare($queue, false, true, false, false);

        $this->info("Waiting for messages in {$queue}. To exit press CTRL+C");

        $callback = function ($msg) {
            $this->info("Received: " . $msg->body);
            
            try {
                $payload = json_decode($msg->body, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                   \App\Jobs\ProcessErpUserUpdate::dispatch($payload);
                   $msg->ack();
                } else {
                    $this->error("Invalid JSON");
                    $msg->nack(false, false); // Reject without requeue
                }
            } catch (\Exception $e) {
                $this->error("Error processing message: " . $e->getMessage());
                $msg->nack(true); // Requeue
            }
        };

        $channel->basic_qos(null, 1, null);
        $channel->basic_consume($queue, '', false, false, false, false, $callback);

        while ($channel->is_consuming()) {
            $channel->wait();
        }

        $channel->close();
        $connection->close();
    }
}
