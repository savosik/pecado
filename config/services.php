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

    /*
     * ApiShip — агрегатор служб доставки (СДЭК, ПЭК, Деловые Линии и др.).
     * Через него склад считает стоимость, заводит заявку и получает трек-номер.
     * См. docs/APISHIP.md.
     */
    'apiship' => [
        // Мастер-выключатель: без него ни один запрос в ApiShip не уходит.
        'enabled' => (bool) env('APISHIP_ENABLED', false),

        /*
         * Боевая среда — https://api.apiship.ru/v1, тестовая — http://api.dev.apiship.ru/v1
         * (именно http, TLS там нет) с логином и паролем `test`. Базы у сред разные.
         */
        'base_url' => rtrim((string) env('APISHIP_BASE_URL', 'https://api.apiship.ru/v1'), '/'),

        /*
         * Готовый API-токен из личного кабинета ApiShip. Если он задан, логин
         * и пароль не нужны вовсе: токен бессрочный, и лишний POST /login перед
         * работой — это только повод получить 401 на ровном месте.
         */
        'token' => env('APISHIP_TOKEN'),
        'login' => env('APISHIP_LOGIN'),
        'password' => env('APISHIP_PASSWORD'),
        'timeout' => (int) env('APISHIP_TIMEOUT', 30),
        // Токен ApiShip бессрочный, но держать его в кэше сутки дешевле, чем
        // логиниться перед каждым запросом. Протухший токен ловится по 401.
        'token_ttl' => (int) env('APISHIP_TOKEN_TTL', 86400),
        // Расчёт тарифов у ApiShip тарифицируется как транзакция — одинаковые
        // запросы в пределах этого окна отдаём из кэша.
        'calculator_cache_ttl' => (int) env('APISHIP_CALCULATOR_CACHE_TTL', 600),

        'webhook' => [
            'enabled' => (bool) env('APISHIP_WEBHOOK_ENABLED', false),
            // Подписи у вебхуков ApiShip нет — секрет живёт сегментом URL.
            'secret' => env('APISHIP_WEBHOOK_SECRET'),
            // Необязательный список IP через запятую. Пусто — проверка по IP выключена.
            'allowed_ips' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('APISHIP_WEBHOOK_ALLOWED_IPS', ''))
            ))),
        ],

        // Отправитель по умолчанию — наш склад. Уезжает в блоке sender каждой заявки.
        'sender' => [
            'company_name' => env('APISHIP_SENDER_COMPANY', 'Pecado'),
            'contact_name' => env('APISHIP_SENDER_CONTACT'),
            'phone' => env('APISHIP_SENDER_PHONE'),
            'email' => env('APISHIP_SENDER_EMAIL'),
            'country_code' => env('APISHIP_SENDER_COUNTRY', 'RU'),
            'region' => env('APISHIP_SENDER_REGION'),
            'city' => env('APISHIP_SENDER_CITY'),
            'street' => env('APISHIP_SENDER_STREET'),
            'house' => env('APISHIP_SENDER_HOUSE'),
            'index' => env('APISHIP_SENDER_INDEX'),
        ],

        'defaults' => [
            /*
             * Вес товаров без заполненных weight_gross/weight_net. Ноль слать нельзя:
             * ошибки API не будет, но перевозчик посчитает тариф по объёмному весу
             * и выставит счёт, отличный от нашей оценки.
             */
            'item_weight_grams' => (int) env('APISHIP_DEFAULT_WEIGHT_GRAMS', 500),
            // Габариты типовой коробки, сантиметры — подставляются в форму мест.
            'place_length' => (int) env('APISHIP_DEFAULT_PLACE_LENGTH', 40),
            'place_width' => (int) env('APISHIP_DEFAULT_PLACE_WIDTH', 30),
            'place_height' => (int) env('APISHIP_DEFAULT_PLACE_HEIGHT', 20),
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
