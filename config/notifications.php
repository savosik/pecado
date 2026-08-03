<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mail notifications
    |--------------------------------------------------------------------------
    |
    | Конфигурация транзакционных email-уведомлений Pecado.ru. Каждый канал
    | управляется отдельным feature-флагом — это позволяет выкатывать код
    | на prod с выключенными письмами и включать поканально через ENV
    | без релиза.
    |
    */

    'mail' => [

        /*
        | Получатели технических/админских уведомлений (Spatie Health, ошибки
        | очередей и т.п.). Список через запятую: `admin@x.ru,ops@x.ru`.
        */
        'admin_recipients' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MAIL_ADMIN_RECIPIENTS', ''))
        ))),

        /*
        | Whitelist статусов заказа, при переходе в которые клиент получает
        | уведомление о смене статуса. Управляется кодом, не через ENV —
        | редактируется при изменении бизнес-процесса. Соответствует
        | значениям App\Enums\OrderStatus.
        */
        'order_statuses_to_notify_client' => [
            'ready_for_shipment',
            'shipping',
            'awaiting_payment',
            'closed',
        ],

        /*
        | Feature-флаги. На prod все по умолчанию false — после деплоя
        | кода и проверки на dev включаются поканально через ENV.
        */
        'features' => [
            'welcome_on_registration' => filter_var(env('MAIL_FEATURE_WELCOME', false), FILTER_VALIDATE_BOOLEAN),
            'password_changed' => filter_var(env('MAIL_FEATURE_PASSWORD_CHANGED', false), FILTER_VALIDATE_BOOLEAN),
            'order_created' => filter_var(env('MAIL_FEATURE_ORDER_CREATED', false), FILTER_VALIDATE_BOOLEAN),
            'order_status_changes' => filter_var(env('MAIL_FEATURE_ORDER_STATUS', false), FILTER_VALIDATE_BOOLEAN),
            'manager_new_order' => filter_var(env('MAIL_FEATURE_MANAGER_ORDER', false), FILTER_VALIDATE_BOOLEAN),
            'return_created' => filter_var(env('MAIL_FEATURE_RETURN_CREATED', false), FILTER_VALIDATE_BOOLEAN),
            'return_status_changes' => filter_var(env('MAIL_FEATURE_RETURN_STATUS', false), FILTER_VALIDATE_BOOLEAN),

            /*
            | Задачи CRM: письмо исполнителю при назначении и напоминание
            | о сроке (команда crm:tasks-remind). Себе задачу ставят чаще,
            | чем коллеге, поэтому автору-исполнителю письмо не уходит.
            */
            'crm_tasks' => filter_var(env('MAIL_FEATURE_CRM_TASKS', false), FILTER_VALIDATE_BOOLEAN),

            /*
            | Универсальные подписки на изменения сущностей разделов кабинета
            | (email). Общий гейт рассылки — см. config/subscriptions.php для
            | пофазного включения отдельных разделов.
            */
            'entity_subscriptions' => filter_var(env('MAIL_FEATURE_ENTITY_SUBSCRIPTIONS', false), FILTER_VALIDATE_BOOLEAN),
        ],

    ],

];
