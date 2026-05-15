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
    | Публикация contractor.created в 1С (US-07 v13.2)
    |--------------------------------------------------------------------------
    |
    | При создании Company с непустым tax_id (или первом заполнении tax_id
    | на апдейте) сайт публикует contractor.created в очередь erp_out.contractors.
    | 1С генерирует свой UUID и возвращает его через contractor.updated.
    | Флаг позволяет быстро отключить publisher без деплоя, если что-то пошло не так.
    |
    */
    'publish_contractors' => env('PUBLISH_CONTRACTORS_TO_ERP', true),

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
    | Shovel с ESB Andrey Company (заказы из чужой 1С)
    |--------------------------------------------------------------------------
    |
    | RabbitMQ Shovel тянет заказы из очереди `pecado.orders` на ESB Andrey
    | (esb.services.andrey.company:45671, AMQPS) и публикует их в локальный
    | fanout-обменник `external.orders_from_andrey`, откуда они расходятся по:
    |   - external.orders_from_andrey_for_website (потребитель — сайт, для аудита/отладки)
    |   - external.orders_from_andrey_for_erp     (потребитель — 1С Pecado)
    |
    | Сценарий: «прокидывание» заказов из 1С Andrey в 1С Pecado через шину.
    | Сайт сам эти сообщения не обрабатывает; очередь _for_website оставлена
    | как зеркало для мониторинга/отладки.
    |
    | Если `ANDREY_ESB_AMQP_URI` пустой — shovel не создаётся (локальный dev / CI).
    |
    */
    'andrey_shovel' => [
        'name' => 'andrey-orders',
        'src_uri' => env('ANDREY_ESB_AMQP_URI'),
        'src_queue' => env('ANDREY_ESB_SRC_QUEUE', 'pecado.orders'),
        'dest_exchange' => 'external.orders_from_andrey',
        'prefetch_count' => (int) env('ANDREY_ESB_SHOVEL_PREFETCH', 100),
        'reconnect_delay' => (int) env('ANDREY_ESB_SHOVEL_RECONNECT_DELAY', 5),
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

    /*
    |--------------------------------------------------------------------------
    | Защита ручных привязок к ProductModel
    |--------------------------------------------------------------------------
    |
    | На сайте часть моделей товаров и их привязок к товарам ведётся вручную
    | через artisan-команду `products:apply-model-bindings` из JSON-артефакта.
    | Такие ProductModel создаются БЕЗ external_id (UUID 1С) — это маркер
    | «ручной» модели.
    |
    | При включённом флаге обработчики ERP product.created/product.updated:
    |   1) не перезаписывают Product.model_id, если у товара уже есть привязка;
    |   2) не проставляют external_id в существующую ProductModel, найденную
    |      по name с пустым external_id (это наша ручная модель — оставляем
    |      её «ручной»).
    |
    | Выключите (false), если хотите вернуть приоритет 1С как мастер-каталога
    | для моделей.
    |
    */
    'product_models' => [
        'preserve_existing' => env('ERP_PRESERVE_PRODUCT_MODEL', true),
    ],
];
