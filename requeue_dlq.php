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
    
    while ($msg = $channel->basic_get('erp_dlq.catalog')) {
        $props = $msg->get_properties();
        
        // Remove x-death headers so it gets a fresh try
        if (isset($props['application_headers'])) {
            $headers = $props['application_headers']->getNativeData();
            unset($headers['x-death'], $headers['x-first-death-exchange'], $headers['x-first-death-reason'], $headers['x-first-death-queue'], $headers['x-last-death-exchange'], $headers['x-last-death-reason']);
            // Reset attempts for laravel array
            if (isset($headers['laravel']) && is_array($headers['laravel'])) {
                $headers['laravel']['attempts'] = 0;
            }
            
            $table = new \PhpAmqpLib\Wire\AMQPTable($headers);
            $props['application_headers'] = $table;
        } else {
            // Add fresh laravel headers if missing
            $table = new \PhpAmqpLib\Wire\AMQPTable(['laravel' => ['attempts' => 0]]);
            $props['application_headers'] = $table;
        }
        
        $newMsg = new \PhpAmqpLib\Message\AMQPMessage($msg->getBody(), $props);
        
        // Publish to default exchange with routing key = queue name 'erp_in.catalog'
        $channel->basic_publish($newMsg, '', 'erp_in.catalog');
        
        // Ack from DLQ
        $channel->basic_ack($msg->getDeliveryTag());
        $moved++;
    }
    
    $channel->close();
    $factory->close();
    echo "SUCCESS: Moved $moved messages to erp_in.catalog\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
