<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class DebugDlqAll extends Command
{
    protected $signature = 'app:debug-dlq-all';

    protected $description = 'Debug DLQ exceptions';

    public function handle()
    {
        try {
            $factory = new AMQPStreamConnection(
                env('RABBITMQ_HOST', 'rabbitmq'),
                env('RABBITMQ_PORT', 5672),
                env('RABBITMQ_LOGIN', 'pecado_app'),
                env('RABBITMQ_PASSWORD', env('RABBITMQ_PASSWORD', 'PecadoApp2024!')),
                env('RABBITMQ_VHOST', '/')
            );
            $channel = $factory->channel();

            $errors = [];
            $total = 0;

            $this->info('Fetching limited DLQ messages...');

            while ($msg = $channel->basic_get('erp_dlq.catalog')) {
                $total++;
                if ($total > 10) {
                    break;
                }

                $payloadStr = $msg->getBody();
                $payload = json_decode($payloadStr, true);

                try {
                    DB::beginTransaction();
                    app(\App\Services\Erp\Handlers\HandleProductCreated::class)->handle($payload);
                    DB::rollBack();
                } catch (\Throwable $e) {
                    DB::rollBack();
                    $err = get_class($e).': '.$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine();
                    if (! isset($errors[$err])) {
                        $errors[$err] = 0;
                    }
                    $errors[$err]++;
                }
            }

            $channel->close();
            $factory->close();

            $this->info("Checked $total messages.");
            if (empty($errors)) {
                $this->info('No errors found! They all succeeded.');
            } else {
                $this->error('Errors found:');
                foreach ($errors as $err => $count) {
                    $this->error("[$count times] $err");
                }
            }
        } catch (\Throwable $e) {
            $this->error('RABBIT ERROR: '.$e->getMessage());
        }
    }
}
