<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'yandex_maps' => [
        'api_key' => env('YANDEX_MAPS_API_KEY', ''),
    ],

    'sex_opt' => [
        'api_token' => env('SEX_OPT_API_TOKEN'),
        'export_url' => env('SEX_OPT_EXPORT_URL'),

        /*
         * Customer API поставщика (Andrey Company / sex-opt.ru): метод send_order.
         * Через него уходят предзаказы клиентов, чтобы поставщик поставил товар
         * в резерв на нужный склад. См. docs/SUPPLIER_PREORDERS.md.
         */
        'order_api_url' => env('SUPPLIER_ORDER_API_URL', 'https://api.sex-opt.ru/customer/api/v1'),
        'order_api_key' => env('SUPPLIER_ORDER_API_KEY'),
        'order_api_timeout' => (int) env('SUPPLIER_ORDER_API_TIMEOUT', 30),

        'preorder' => [
            // Мастер-выключатель: без него ни одна отправка не уходит
            'enabled' => (bool) env('SUPPLIER_PREORDER_ENABLED', false),
            // Склад поставщика: msk | tmn
            'stock' => env('SUPPLIER_PREORDER_STOCK', 'tmn'),
            /*
             * testmode: поставщик выполняет запрос и откатывает транзакцию.
             * По умолчанию боевой режим только на проде — на dev/local шлём тестом,
             * чтобы не плодить мусорные заказы у поставщика.
             */
            'testmode' => (bool) env('SUPPLIER_PREORDER_TESTMODE', env('APP_ENV') !== 'production'),
            /*
             * rollback_on_warnings: откатывать заказ целиком, если поставщик
             * смог набрать не всё (нехватка остатка, неизвестные коды).
             * По умолчанию false — частичный резерв лучше, чем никакого.
             */
            'rollback_on_warnings' => (bool) env('SUPPLIER_PREORDER_ROLLBACK_ON_WARNINGS', false),
        ],
    ],

    'dadata' => [
        'api_key' => env('DADATA_API_KEY'),
        'secret_key' => env('DADATA_SECRET_KEY'),
        'suggestions_url' => env('DADATA_SUGGESTIONS_URL', 'https://suggestions.dadata.ru/suggestions/api/4_1/rs'),
        'cache_ttl' => (int) env('DADATA_CACHE_TTL', 86400),
        'request_timeout' => (int) env('DADATA_REQUEST_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | OAuth Providers
    |--------------------------------------------------------------------------
    */

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URL', '/auth/google/callback'),
    ],

    'yandex' => [
        'client_id' => env('YANDEX_CLIENT_ID'),
        'client_secret' => env('YANDEX_CLIENT_SECRET'),
        'redirect' => env('YANDEX_REDIRECT_URL', '/auth/yandex/callback'),
    ],

    'vkontakte' => [
        'client_id' => env('VK_CLIENT_ID'),
        'client_secret' => env('VK_CLIENT_SECRET'),
        'redirect' => env('VK_REDIRECT_URL', '/auth/vkontakte/callback'),
    ],

    'mailru' => [
        'client_id' => env('MAILRU_CLIENT_ID'),
        'client_secret' => env('MAILRU_CLIENT_SECRET'),
        'redirect' => env('MAILRU_REDIRECT_URL', '/auth/mailru/callback'),
    ],

];
