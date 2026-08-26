<?php

/**
 * Поводы, по которым система сама собирает письмо.
 *
 * Раньше на каждый повод существовал класс с описанием полей, меток и групп —
 * это было нужно конструктору правил, который спрашивал «какое событие?».
 * Новый конструктор спрашивает «какое письмо?», поэтому от повода осталось
 * ровно то, что видно в письме: название и заготовка темы.
 *
 * Подстановки темы раскрываются простой заменой строк (CrmEmailTemplate::render),
 * а не шаблонизатором: шаблон приходит из конфига, но значения — из данных 1С.
 *
 * Ключ, которого здесь нет, письма не порождает. Это и защита от опечатки
 * в доменном коде, и способ выключить повод, не трогая место, где он случается.
 */
return [
    'orders.created' => [
        'label' => 'Оформлен заказ',
        'subject' => 'Заказ {{order_number}} принят',
        'default_destinations' => [['type' => 'login']],
        'default_enabled' => true,
        'client_visible' => true,
    ],
    'orders.status_changed' => [
        'label' => 'Смена статуса заказа',
        'subject' => 'Заказ {{order_number}}: {{status_label}}',
        'default_destinations' => [['type' => 'login']],
        'default_enabled' => true,
        'client_visible' => true,
        // Клиенту интересны переходы, которые он видит физически. Остальные
        // шесть — внутренняя кухня: «Готов к закрытию» в почте клиента
        // появляться не должен, а до матрицы поток собирал и его.
        'default_options' => [
            'statuses' => ['ready_for_shipment', 'shipping', 'awaiting_payment'],
        ],
    ],
    'orders.items_updated' => [
        'label' => 'Изменился состав заказа',
        'subject' => 'Заказ {{order_number}}: изменился состав',
        'default_destinations' => [['type' => 'login']],
        'default_enabled' => true,
        'client_visible' => true,
    ],
    'orders.attributes_updated' => [
        'label' => 'Изменились реквизиты заказа',
        'subject' => 'Заказ {{order_number}}: изменились реквизиты',
        'default_destinations' => [['type' => 'login']],
        'default_enabled' => true,
        'client_visible' => true,
    ],
    'orders.shortfall' => [
        'label' => 'Недобор по заказу',
        'subject' => 'Заказ {{order_number}}: часть позиций не набралась',
        'default_destinations' => [['type' => 'manager']],
        'default_enabled' => true,
        'client_visible' => true,
    ],
    'orders.substitution_offered' => [
        'label' => 'Подобрана замена по недобору',
        'subject' => 'Заказ {{order_number}}: подобрали замену',
        'default_destinations' => [['type' => 'login']],
        'default_enabled' => true,
        'client_visible' => true,
    ],
    'orders.shipped' => [
        'label' => 'Отгрузка по заказу',
        'subject' => 'Заказ {{order_number}}: отгружен',
        'default_destinations' => [['type' => 'login']],
        'default_enabled' => true,
        'client_visible' => true,
    ],

    'documents.published' => [
        'label' => 'Опубликован документ',
        'subject' => '{{document_title}} — Pecado.ru',
        'default_destinations' => [['type' => 'login']],
        'default_enabled' => true,
        'client_visible' => true,
    ],
    'documents.deleted' => [
        'label' => 'Документ отозван',
        'subject' => 'Документ отозван — Pecado.ru',
        'default_destinations' => [['type' => 'manager']],
        'default_enabled' => true,
        'client_visible' => true,
    ],

    'finance.payment_due_soon' => [
        'label' => 'Подходит срок оплаты',
        'subject' => 'Напоминание об оплате — Pecado.ru',
        'default_destinations' => [['type' => 'manager']],
        'default_enabled' => true,
        'client_visible' => true,
    ],
    'finance.overdue_started' => [
        'label' => 'Возникла просрочка',
        'subject' => 'Просроченная задолженность — Pecado.ru',
        'default_destinations' => [['type' => 'manager']],
        'default_enabled' => true,
        'client_visible' => true,
    ],
    'finance.overdue_grew' => [
        'label' => 'Просрочка выросла',
        'subject' => 'Просроченная задолженность выросла — Pecado.ru',
        'default_destinations' => [['type' => 'manager']],
        'default_enabled' => true,
        'client_visible' => true,
    ],
    'finance.overdue_cleared' => [
        'label' => 'Просрочка погашена',
        'subject' => 'Задолженность погашена — спасибо!',
        'default_destinations' => [['type' => 'manager']],
        'default_enabled' => true,
        'client_visible' => true,
    ],

    'system.return_created' => [
        'label' => 'Оформлен возврат',
        'subject' => 'Возврат {{order_number}} принят',
        'default_destinations' => [['type' => 'login']],
        'default_enabled' => true,
        'client_visible' => true,
    ],
    'system.return_status_changed' => [
        'label' => 'Смена статуса возврата',
        'subject' => 'Возврат {{order_number}}: изменился статус',
        'default_destinations' => [['type' => 'login']],
        'default_enabled' => true,
        'client_visible' => true,
    ],
    'system.question_received' => [
        'label' => 'Вопрос с сайта',
        'subject' => 'Новый вопрос с сайта',
        'default_destinations' => [['type' => 'manager']],
        'default_enabled' => true,
        'client_visible' => false,
    ],
];
