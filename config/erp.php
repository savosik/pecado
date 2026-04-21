<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Логирование сообщений шины ERP
    |--------------------------------------------------------------------------
    |
    | Когда включено, все входящие и исходящие сообщения RabbitMQ
    | сохраняются в таблицу erp_bus_messages для отладки.
    | В production по умолчанию выключено.
    |
    */
    'bus_logging_enabled' => env('ERP_BUS_LOGGING_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Shovel с московского ESB (внешние остатки)
    |--------------------------------------------------------------------------
    |
    | RabbitMQ Shovel тянет сообщения об остатках из очереди на ESB
    | (`remains_for_moscow`) и публикует их в локальный fanout-обменник
    | `external.remains`, откуда они расходятся по двум очередям:
    |   - external.remains_for_website (потребитель — сайт Pecado)
    |   - external.remains_for_erp     (потребитель — 1С)
    |
    | Параметры shovel-а задаются на нашем rabbitmq через Management HTTP API
    | в команде `php artisan rabbitmq:setup`. Если `src_uri` пустой —
    | shovel не создаётся (локальный dev без доступа к ESB).
    |
    */
    'moscow_shovel' => [
        'name' => 'moscow-remains',
        'src_uri' => env('MOSCOW_ESB_AMQP_URI'),
        'src_queue' => env('MOSCOW_ESB_SRC_QUEUE', 'remains_for_moscow'),
        'dest_exchange' => 'external.remains',
        'prefetch_count' => (int) env('MOSCOW_ESB_SHOVEL_PREFETCH', 1000),
        'reconnect_delay' => (int) env('MOSCOW_ESB_SHOVEL_RECONNECT_DELAY', 5),
    ],
];
