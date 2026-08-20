<?php

use App\Notifications\Pulse\Events\Documents;
use App\Notifications\Pulse\Events\Finance;
use App\Notifications\Pulse\Events\Orders;

return [

    /*
    |--------------------------------------------------------------------------
    | Пульт уведомлений
    |--------------------------------------------------------------------------
    |
    | Единый центр маршрутизации исходящих сообщений: событие → условия →
    | получатели. Правила ведут РОП и менеджер в CRM, движок решает, кому
    | и что отправить, журнал показывает, что и почему ушло.
    |
    */

    /*
    | Полный стоп-кран. Выключено — движок не обрабатывает ни одного сигнала,
    | старые листенеры работают как раньше.
    */
    'enabled' => filter_var(env('NOTIFICATION_PULSE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    /*
    | Режим работы:
    |   'off'    — сигналы не принимаются вовсе;
    |   'shadow' — движок считает получателей и пишет журнал, но не отправляет.
    |              Так проверяется совпадение с текущим поведением до перехода;
    |   'live'   — с отправкой.
    |
    | По умолчанию shadow: включение пульта не должно само по себе начать
    | рассылать письма.
    */
    'mode' => env('NOTIFICATION_PULSE_MODE', 'shadow'),

    /*
    | Пособытийный перевод в боевой режим на время миграции. Пока ключ события
    | не перечислен здесь, письмо по нему шлёт старый листенер, а пульт молчит.
    | Один и тот же список читают обе стороны (PulseMode::handles), поэтому
    | двойная отправка невозможна по конструкции, а не по внимательности.
    |
    | Пусто и mode=live — пульт обрабатывает все включённые домены.
    */
    'live_events' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('NOTIFICATION_PULSE_LIVE_EVENTS', ''))
    ))),

    /*
    | Домены событий. Выключенный домен не порождает сигналов вовсе —
    | это гейт для пофазного включения без релиза.
    */
    'domains' => [
        'orders' => [
            'label' => 'Заказы',
            'enabled' => filter_var(env('PULSE_DOMAIN_ORDERS', true), FILTER_VALIDATE_BOOLEAN),
        ],
        'documents' => [
            'label' => 'Документы',
            'enabled' => filter_var(env('PULSE_DOMAIN_DOCUMENTS', false), FILTER_VALIDATE_BOOLEAN),
        ],
        'finance' => [
            'label' => 'Оплаты',
            'enabled' => filter_var(env('PULSE_DOMAIN_FINANCE', false), FILTER_VALIDATE_BOOLEAN),
        ],
        'campaigns' => [
            'label' => 'Рассылки',
            'enabled' => filter_var(env('PULSE_DOMAIN_CAMPAIGNS', false), FILTER_VALIDATE_BOOLEAN),
        ],
        'system' => [
            'label' => 'Служебные',
            'enabled' => filter_var(env('PULSE_DOMAIN_SYSTEM', false), FILTER_VALIDATE_BOOLEAN),
        ],
    ],

    /*
    | Реестр событий. Добавление нового события — строка здесь плюс класс:
    | конструктор, условия, метки, журнал и трасса подхватывают его сами.
    */
    'events' => [
        Orders\OrderCreatedEvent::class,
        Orders\OrderStatusChangedEvent::class,
        Orders\OrderItemsUpdatedEvent::class,
        Orders\OrderAttributesUpdatedEvent::class,
        Orders\OrderShortfallEvent::class,
        Orders\SubstitutionOfferedEvent::class,
        Orders\OrderShippedEvent::class,

        Documents\DocumentPublishedEvent::class,
        Documents\DocumentDeletedEvent::class,

        Finance\PaymentDueSoonEvent::class,
        Finance\OverdueStartedEvent::class,
        Finance\OverdueGrewEvent::class,
        Finance\OverdueClearedEvent::class,
    ],

    /*
    | Белый список ключей конфигурации, доступных получателю kind=config_list.
    | Без него правило смогло бы прочитать любой ключ конфигурации приложения.
    | Аварийные резервные адреса остаются в ENV и не размазываются по базе.
    */
    'config_recipient_lists' => [
        'notifications.mail.order_fallback_recipients' => 'Резервные адреса по заказам',
        'notifications.mail.purchasing_recipients' => 'Закупки',
        'notifications.mail.user_question_recipients' => 'Вопросы с сайта',
        'notifications.mail.admin_recipients' => 'Технические адреса',
    ],

    'limits' => [
        /*
        | Потолок отправки. Превышение уходит в журнал со skip_reason=rate_limited
        | и предупреждением в лог: лучше недоставить часть, чем разослать лавину.
        */
        'max_deliveries_per_minute' => (int) env('PULSE_MAX_PER_MINUTE', 120),

        /*
        | Возрастной ценз — главный предохранитель домена. Событие старше этого
        | срока не рассылается вовсе, поэтому первичная выгрузка истории из 1С
        | или пересчёт балансов физически не могут разослать письма.
        */
        'max_signal_age_minutes' => (int) env('PULSE_MAX_SIGNAL_AGE', 120),

        /* Больше этого размера вложение заменяется ссылкой на кабинет. */
        'max_attachment_bytes' => 5 * 1024 * 1024,

        /* Страховка от правила, раскрывшегося в сотни адресов. */
        'max_recipients_per_signal' => (int) env('PULSE_MAX_RECIPIENTS', 20),

        /* Сколько правил может завести себе сам клиент в кабинете, на раздел. */
        'max_self_rules_per_domain' => 5,
    ],

    'retention' => [
        /* Сигналы — оперативная трасса, нужна недолго. */
        'signals_days' => (int) env('PULSE_SIGNAL_RETENTION_DAYS', 30),

        /*
        | Доставки живут дольше журнала писем (180 дней): вопрос «когда мы
        | перестали слать этому бухгалтеру» задают и через год, а строка
        | компактная — тела письма здесь нет.
        */
        'deliveries_days' => (int) env('PULSE_DELIVERY_RETENTION_DAYS', 365),
    ],

];
