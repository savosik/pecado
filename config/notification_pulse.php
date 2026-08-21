<?php

use App\Notifications\Pulse\Events\Campaigns;
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

        Campaigns\BroadcastEvent::class,
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

    /*
    | Пресеты — готовые наборы правил под типовые нужды контрагента.
    |
    | Живут в конфиге, а не в базе: набор правил это решение команды о том,
    | как ведётся клиент, а не пользовательские данные. Версионируются
    | вместе с релизом.
    |
    | Получатели заданы ролями: одно применение покрывает контрагента целиком,
    | а нового бухгалтера правило подхватит само.
    */
    'presets' => [

        'orders_control' => [
            'label' => 'Полный контроль заказов',
            'description' => 'Недоборы закупщику, статусы клиенту, закрытие заказа директору.',
            'rules' => [
                [
                    'name' => 'Недобор по заказу — закупщику',
                    'event_key' => 'orders.shortfall',
                    'priority' => 100,
                    'recipients' => [['kind' => 'contact_role', 'value' => 'buyer']],
                ],
                [
                    'name' => 'Изменился состав заказа — закупщику',
                    'event_key' => 'orders.items_updated',
                    'priority' => 110,
                    'throttle_seconds' => 300,
                    'recipients' => [['kind' => 'contact_role', 'value' => 'buyer']],
                ],
                [
                    'name' => 'Заказ закрыт — директору',
                    'event_key' => 'orders.status_changed',
                    'priority' => 50,
                    'stop_processing' => true,
                    'conditions' => ['field' => 'status', 'op' => 'in', 'value' => ['closed']],
                    'recipients' => [['kind' => 'contact_role', 'value' => 'director']],
                ],
                [
                    'name' => 'Смена статуса — клиенту',
                    'event_key' => 'orders.status_changed',
                    'priority' => 120,
                    'recipients' => [['kind' => 'client_user']],
                ],
            ],
        ],

        'accounting' => [
            'label' => 'Бухгалтерия',
            'description' => 'Акты сверки и УПД бухгалтеру, срок оплаты и просрочка ему же, тяжёлая просрочка — директору.',
            'rules' => [
                [
                    'name' => 'Акты сверки — бухгалтеру',
                    'event_key' => 'documents.published',
                    'priority' => 100,
                    'conditions' => ['field' => 'document_type', 'op' => 'in', 'value' => ['reconciliation_act', 'act']],
                    'recipients' => [['kind' => 'contact_role', 'value' => 'accountant']],
                ],
                [
                    'name' => 'Счета и УПД — бухгалтеру',
                    'event_key' => 'documents.published',
                    'priority' => 110,
                    'conditions' => ['field' => 'document_type', 'op' => 'in', 'value' => ['invoice', 'upd', 'tax_invoice']],
                    'recipients' => [['kind' => 'contact_role', 'value' => 'accountant']],
                ],
                [
                    'name' => 'Подходит срок оплаты — бухгалтеру',
                    'event_key' => 'finance.payment_due_soon',
                    'priority' => 100,
                    'attach_documents' => true,
                    'recipients' => [['kind' => 'contact_role', 'value' => 'accountant']],
                ],
                [
                    'name' => 'Просрочка — бухгалтеру',
                    'event_key' => 'finance.overdue_started',
                    'priority' => 100,
                    'recipients' => [['kind' => 'contact_role', 'value' => 'accountant']],
                ],
                [
                    'name' => 'Просрочка от 60 дней — директору',
                    'event_key' => 'finance.overdue_grew',
                    'priority' => 90,
                    'conditions' => ['field' => 'days_overdue', 'op' => '>=', 'value' => 60],
                    'recipients' => [
                        ['kind' => 'contact_role', 'value' => 'director'],
                        ['kind' => 'contact_role', 'value' => 'accountant'],
                    ],
                ],
            ],
        ],

        'logistics' => [
            'label' => 'Логистика',
            'description' => 'Отгрузки и товаросопроводительные документы логисту.',
            'rules' => [
                [
                    'name' => 'Отгрузка по заказу — логисту',
                    'event_key' => 'orders.shipped',
                    'priority' => 100,
                    'recipients' => [['kind' => 'contact_role', 'value' => 'logist']],
                ],
                [
                    'name' => 'Накладные — логисту',
                    'event_key' => 'documents.published',
                    'priority' => 120,
                    'conditions' => ['field' => 'document_type', 'op' => 'in', 'value' => ['waybill', 'consignment_note']],
                    'recipients' => [['kind' => 'contact_role', 'value' => 'logist']],
                ],
            ],
        ],

        'critical_only' => [
            'label' => 'Только критичное',
            'description' => 'Недоборы и тяжёлая просрочка. Для клиентов, которые просят не заваливать почту.',
            'rules' => [
                [
                    'name' => 'Недобор по заказу — закупщику',
                    'event_key' => 'orders.shortfall',
                    'priority' => 100,
                    'recipients' => [['kind' => 'contact_role', 'value' => 'buyer']],
                ],
                [
                    'name' => 'Просрочка от 90 дней — директору',
                    'event_key' => 'finance.overdue_grew',
                    'priority' => 100,
                    'conditions' => ['field' => 'days_overdue', 'op' => '>=', 'value' => 90],
                    'recipients' => [['kind' => 'contact_role', 'value' => 'director']],
                ],
            ],
        ],

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
