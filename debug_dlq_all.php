<?php

try {
    $factory = new \PhpAmqpLib\Connection\AMQPStreamConnection(
        env('RABBITMQ_HOST', 'rabbitmq'),
        env('RABBITMQ_PORT', 5672),
        env('RABBITMQ_LOGIN', 'pecado_app'),
        env('RABBITMQ_PASSWORD', env('RABBITMQ_PASSWORD', 'PecadoApp2024!')),
        env('RABBITMQ_VHOST', '/')
    );
    $channel = $factory->channel();

    $errors = [];
    $total = 0;

    echo "Fetching all DLQ messages...\n";

    while ($msg = $channel->basic_get('erp_dlq.catalog')) {
        $total++;
        $payloadStr = $msg->getBody();
        $payload = json_decode($payloadStr, true);

        $uuid = $payload['uuid'] ?? 'no-uuid';

        try {
            DB::beginTransaction();
            app(\App\Services\Erp\Handlers\HandleProductCreated::class)->handle($payload);
            DB::rollBack(); // roll back so we don't actually commit during debug

            // If we're here, it succeeded
            // Let's just track successes? No just errors
        } catch (\Throwable $e) {
            DB::rollBack();
            $err = $e->getMessage().' at '.$e->getFile().':'.$e->getLine();
            if (! isset($errors[$err])) {
                $errors[$err] = 0;
            }
            $errors[$err]++;
        }
    }

    $channel->close();
    $factory->close();

    echo "Checked $total messages.\n";
    if (empty($errors)) {
        echo "No errors found! They all succeeded.\n";
    } else {
        echo "Errors found:\n";
        foreach ($errors as $err => $count) {
            echo "[$count times] $err\n";
        }
    }
} catch (\Throwable $e) {
    echo 'RABBIT ERROR: '.$e->getMessage()."\n";
}
