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
// Клиентские уведомления по умолчанию ВЫКЛЮЧЕНЫ. Решение заказчика 27.08.2026:
// «в матрицах клиентов просто отключи получение писем, мы потом будем настраивать
// каждому индивидуально».
//
// Сделано умолчанием, а не записью строк каждому: ноль строк в базе, новые
// партнёры из 1С выключены сразу, откат — одна правка здесь. Партнёры, которым
// уведомление включили руками, от смены умолчания не страдают: у них своя строка.
//
// Внутренние поводы (адресат — менеджер) остаются включёнными: ослепить отдел
// продаж заодно с клиентами не просили.
return [
    'orders.created' => [
        'label' => 'Оформлен заказ',
        'subject' => 'Заказ {{order_number}} принят',
        'default_destinations' => [['type' => 'login']],
        'default_enabled' => false,
        'client_visible' => true,
    ],
    'orders.status_changed' => [
        'label' => 'Смена статуса заказа',
        'subject' => 'Заказ {{order_number}}: {{status_label}}',
        'default_destinations' => [['type' => 'login']],
        'default_enabled' => false,
        'client_visible' => true,
        // Подтип уведомления: о каких именно статусах писать. Клиенту интересны
        // переходы, которые он видит физически; остальные шесть — внутренняя
        // кухня, и «Готов к закрытию» в его почте появляться не должен.
        'subtype' => [
            'field' => 'status',
            'label' => 'О каких статусах писать',
            'source' => \App\Enums\OrderStatus::class,
        ],
        'default_options' => [
            'subtypes' => ['ready_for_shipment', 'shipping', 'awaiting_payment'],
        ],
    ],
    'orders.items_updated' => [
        'label' => 'Изменился состав заказа',
        'subject' => 'Заказ {{order_number}}: изменился состав',
        'default_destinations' => [['type' => 'login']],
        'default_enabled' => false,
        'client_visible' => true,
    ],
    'orders.attributes_updated' => [
        'label' => 'Изменились реквизиты заказа',
        'subject' => 'Заказ {{order_number}}: изменились реквизиты',
        'default_destinations' => [['type' => 'login']],
        'default_enabled' => false,
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
        'default_enabled' => false,
        'client_visible' => true,
    ],
    'orders.shipped' => [
        'label' => 'Отгрузка по заказу',
        'subject' => 'Заказ {{order_number}}: отгружен',
        'default_destinations' => [['type' => 'login']],
        'default_enabled' => false,
        'client_visible' => true,
    ],

    'documents.published' => [
        'label' => 'Опубликован документ',
        // Кому-то нужны только счета, кому-то только акты сверки. Пустой набор
        // означает «все типы»: незаполненная настройка не должна означать тишину.
        'subtype' => [
            'field' => 'document_type',
            'label' => 'О каких документах писать',
            'source' => \App\Enums\PrintedDocumentType::class,
        ],
        'subject' => '{{document_title}} — Pecado.ru',
        'default_destinations' => [['type' => 'login']],
        'default_enabled' => false,
        'client_visible' => true,
    ],
    // Периодические поводы. Их придумали не ради красоты: 1С выкладывает акты
    // сверки каждый день, и подписка на «опубликован документ» дала бы клиенту
    // ежедневное письмо, которое он перестанет замечать через неделю.
    //
    // Периодичность выражена **отдельным событием**, а не настройкой «раз
    // в неделю» у существующего. Иначе в матрицу вернулись бы расписания
    // и условия — то есть заново собрался бы движок правил.
    'documents.reconciliation_weekly' => [
        'label' => 'Акты сверки — сводка за неделю',
        'subject' => 'Акты сверки за неделю — Pecado.ru',
        'default_destinations' => [['type' => 'login']],
        'default_enabled' => false,
        'client_visible' => true,
    ],
    'finance.reconciliation_due' => [
        'label' => 'Сверка при непогашенном долге',
        'subject' => 'Сверка расчётов — Pecado.ru',
        'default_destinations' => [['type' => 'login']],
        'default_enabled' => false,
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
        'default_enabled' => false,
        'client_visible' => true,
    ],
    'system.return_status_changed' => [
        'label' => 'Смена статуса возврата',
        'subject' => 'Возврат {{order_number}}: изменился статус',
        'default_destinations' => [['type' => 'login']],
        'default_enabled' => false,
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
