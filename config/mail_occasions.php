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
    ],
    'orders.status_changed' => [
        'label' => 'Смена статуса заказа',
        'subject' => 'Заказ {{order_number}}: {{status_label}}',
    ],
    'orders.items_updated' => [
        'label' => 'Изменился состав заказа',
        'subject' => 'Заказ {{order_number}}: изменился состав',
    ],
    'orders.attributes_updated' => [
        'label' => 'Изменились реквизиты заказа',
        'subject' => 'Заказ {{order_number}}: изменились реквизиты',
    ],
    'orders.shortfall' => [
        'label' => 'Недобор по заказу',
        'subject' => 'Заказ {{order_number}}: часть позиций не набралась',
    ],
    'orders.substitution_offered' => [
        'label' => 'Подобрана замена по недобору',
        'subject' => 'Заказ {{order_number}}: подобрали замену',
    ],
    'orders.shipped' => [
        'label' => 'Отгрузка по заказу',
        'subject' => 'Заказ {{order_number}}: отгружен',
    ],

    'documents.published' => [
        'label' => 'Опубликован документ',
        'subject' => '{{document_title}} — Pecado.ru',
    ],
    'documents.deleted' => [
        'label' => 'Документ отозван',
        'subject' => 'Документ отозван — Pecado.ru',
    ],

    'finance.payment_due_soon' => [
        'label' => 'Подходит срок оплаты',
        'subject' => 'Напоминание об оплате — Pecado.ru',
    ],
    'finance.overdue_started' => [
        'label' => 'Возникла просрочка',
        'subject' => 'Просроченная задолженность — Pecado.ru',
    ],
    'finance.overdue_grew' => [
        'label' => 'Просрочка выросла',
        'subject' => 'Просроченная задолженность выросла — Pecado.ru',
    ],
    'finance.overdue_cleared' => [
        'label' => 'Просрочка погашена',
        'subject' => 'Задолженность погашена — спасибо!',
    ],

    'system.return_created' => [
        'label' => 'Оформлен возврат',
        'subject' => 'Возврат {{order_number}} принят',
    ],
    'system.return_status_changed' => [
        'label' => 'Смена статуса возврата',
        'subject' => 'Возврат {{order_number}}: изменился статус',
    ],
    'system.question_received' => [
        'label' => 'Вопрос с сайта',
        'subject' => 'Новый вопрос с сайта',
    ],
];
