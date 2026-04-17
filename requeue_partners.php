<?php

$moved = 0;
try {
    $factory = new \PhpAmqpLib\Connection\AMQPStreamConnection(
        env('RABBITMQ_HOST', 'rabbitmq'),
        env('RABBITMQ_PORT', 5672),
        env('RABBITMQ_LOGIN', 'pecado_app'),
        env('RABBITMQ_PASSWORD', env('RABBITMQ_PASSWORD', 'PecadoApp2024!')),
        env('RABBITMQ_VHOST', '/')
    );
    $channel = $factory->channel();

    while ($msg = $channel->basic_get('erp_dlq.partners')) {
        $props = ['content_type' => 'application/json', 'delivery_mode' => 2];
        $newMsg = new \PhpAmqpLib\Message\AMQPMessage($msg->getBody(), $props);
        $channel->basic_publish($newMsg, '', 'erp_in.partners');
        $channel->basic_ack($msg->getDeliveryTag());
        $moved++;
    }

    $channel->close();
    $factory->close();
    echo "SUCCESS: Moved $moved messages to erp_in.partners\n";
} catch (\Throwable $e) {
    echo 'ERROR: '.$e->getMessage()."\n";
}
