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
    
    if ($msg = $channel->basic_get('erp_dlq.catalog')) {
        $headers = [];
        if (isset($msg->get_properties()['application_headers'])) {
            $headers = $msg->get_properties()['application_headers']->getNativeData();
        }
        var_dump($headers);
        $payloadStr = $msg->getBody();
        $payload = json_decode($payloadStr, true);
        echo "UUID: " . ($payload['uuid'] ?? 'none') . "\n";
    } else {
        echo "Queue is empty!\n";
    }
    
    $channel->close();
    $factory->close();
} catch (\Throwable $e) {
    echo "RABBIT ERROR: " . $e->getMessage() . "\n";
}
