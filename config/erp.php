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

    /*
    |--------------------------------------------------------------------------
    | TTL сообщений в очередях external.remains_for_*
    |--------------------------------------------------------------------------
    |
    | Применяется через RabbitMQ policy (pattern `^external\.remains_for_.*$`).
    | По умолчанию 3 дня — совпадает с TTL очереди-источника на ESB,
    | чтобы в локальных очередях не накапливалось больше, чем на источнике.
    |
    */
    'external_remains_ttl_ms' => (int) env('EXTERNAL_REMAINS_TTL_MS', 3 * 24 * 60 * 60 * 1000),

    /*
    |--------------------------------------------------------------------------
    | Consumer внешних остатков (external.remains_for_website)
    |--------------------------------------------------------------------------
    |
    | Потребитель очереди external.remains_for_website принимает события
    | `product.quantity.updated` из московского ESB (полная карточка товара
    | с массивом остатков по 12 складам). В БД сайта мы используем остатки
    | только по одному складу — «Тюмень Основной», UUID которого задаётся
    | ниже. Остальные склады в сообщении игнорируются.
    |
    | Доступный остаток вычисляется как max(0, quantity - reserve).
    |
    */
    'external_remains' => [
        'tyumen_warehouse_uuid' => env(
            'EXTERNAL_REMAINS_TYUMEN_WAREHOUSE_UUID',
            'f8083799-0838-11e0-a1ea-505054503030',
        ),
    ],
];
