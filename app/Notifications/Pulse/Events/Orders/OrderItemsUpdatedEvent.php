<?php

namespace App\Notifications\Pulse\Events\Orders;

use App\Notifications\Pulse\Events\AbstractNotificationEvent;
use App\Notifications\Pulse\Support\FieldSpec;

/**
 * Изменение состава заказа.
 *
 * 1С правит заказ построчно, поэтому событий подряд может быть много — от
 * лавины писем защищает троттлинг правила и дайджест.
 *
 * Признаки берутся из готовой структуры OrderChangeLog.changes: ветки added,
 * removed и modified уже формируются журналом изменений заказа.
 */
class OrderItemsUpdatedEvent extends AbstractNotificationEvent
{
    public function key(): string
    {
        return 'orders.items_updated';
    }

    public function label(): string
    {
        return 'Изменился состав заказа';
    }

    public function description(): string
    {
        return 'Добавились, выбыли или изменились позиции заказа';
    }

    public function fields(): array
    {
        return [
            'added_count' => new FieldSpec('added_count', 'Добавлено позиций', FieldSpec::TYPE_NUMBER),
            'removed_count' => new FieldSpec('removed_count', 'Выбыло позиций', FieldSpec::TYPE_NUMBER),
            'modified_count' => new FieldSpec('modified_count', 'Изменено позиций', FieldSpec::TYPE_NUMBER),
            'old_total' => new FieldSpec('old_total', 'Сумма до изменения', FieldSpec::TYPE_MONEY),
            'new_total' => new FieldSpec('new_total', 'Сумма после изменения', FieldSpec::TYPE_MONEY),
            'total_delta' => new FieldSpec('total_delta', 'Изменение суммы', FieldSpec::TYPE_MONEY,
                hint: 'Отрицательное — заказ уменьшился'),
            'has_removed' => new FieldSpec('has_removed', 'Есть выбывшие позиции', FieldSpec::TYPE_BOOL),
            'source' => new FieldSpec('source', 'Источник правки', FieldSpec::TYPE_ENUM, [
                ['value' => 'erp', 'label' => '1С'],
                ['value' => 'admin', 'label' => 'Админка'],
                ['value' => 'api', 'label' => 'API клиента'],
            ]),
        ];
    }

    protected function ownTags(array $data): array
    {
        return ($data['has_removed'] ?? false) ? ['состав:есть-выбывшие'] : [];
    }

    public function defaultSubject(): string
    {
        return 'Заказ {{order_number}}: изменился состав';
    }
}
