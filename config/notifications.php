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
        | Резервные получатели письма о новом заказе — используются, только
        | когда у клиента нет персонального менеджера (или у карточки менеджера
        | пустой email). Список через запятую. Пусто = письмо не отправляется.
        | Основной адресат всегда персональный менеджер клиента, см.
        | App\Support\Notifications\OrderManagerRouting.
        */
        'order_fallback_recipients' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MAIL_ORDER_FALLBACK_RECIPIENTS', ''))
        ))),

        /*
        | Журнал исходящих писем (`sent_emails`): что и кому фактически ушло.
        | Питает ленту карточки партнёра и отвечает на вопрос об адресации.
        | Ретенция в днях — 0 или меньше означает «не чистить».
        */
        'journal_enabled' => filter_var(env('MAIL_JOURNAL_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'journal_retention_days' => (int) env('MAIL_JOURNAL_RETENTION_DAYS', 180),

        /*
        | Получатели уведомления о новом вопросе клиента с сайта. Список через
        | запятую. Пусто = письма не отправляются, вопрос всё равно виден
        | в админке — адресация по ролям здесь была бы тем же антипаттерном,
        | что и в заказах.
        */
        'user_question_recipients' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MAIL_USER_QUESTION_RECIPIENTS', ''))
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
            | Исходящие письма менеджеров из CRM. При выключенном флаге черновики
            | создаются и сохраняются, но отправка заблокирована — это позволяет
            | выкатить код на prod и проверить составление письма до включения
            | реальной отправки клиентам.
            */
            'crm_outbound' => filter_var(env('MAIL_FEATURE_CRM_OUTBOUND', false), FILTER_VALIDATE_BOOLEAN),

            /*
            | Универсальные подписки на изменения сущностей разделов кабинета
            | (email). Общий гейт рассылки — см. config/subscriptions.php для
            | пофазного включения отдельных разделов.
            */
            'entity_subscriptions' => filter_var(env('MAIL_FEATURE_ENTITY_SUBSCRIPTIONS', false), FILTER_VALIDATE_BOOLEAN),
        ],

    ],

];
