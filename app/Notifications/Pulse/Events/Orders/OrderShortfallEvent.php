<?php

namespace App\Notifications\Pulse\Events\Orders;

use App\Notifications\Pulse\Events\AbstractNotificationEvent;
use App\Notifications\Pulse\Support\FieldSpec;

/**
 * Недобор: часть позиций не набралась.
 *
 * Ключевое событие домена для отдела: именно недобор менеджер сегодня руками
 * пересылает закупщику клиента в мессенджер.
 *
 * Два источника: приём заказа по API не в полном объёме (остатков не хватило)
 * и отмена строк при сборке на складе.
 */
class OrderShortfallEvent extends AbstractNotificationEvent
{
    public function key(): string
    {
        return 'orders.shortfall';
    }

    public function label(): string
    {
        return 'Недобор по заказу';
    }

    public function description(): string
    {
        return 'Часть позиций не принята из-за остатков или отменена при сборке';
    }

    public function fields(): array
    {
        return [
            'shortfall_items_count' => new FieldSpec('shortfall_items_count', 'Позиций с недобором', FieldSpec::TYPE_NUMBER),
            'shortfall_amount' => new FieldSpec('shortfall_amount', 'Сумма недобора', FieldSpec::TYPE_MONEY),
            'is_full_cancel' => new FieldSpec('is_full_cancel', 'Заказ не набрался совсем', FieldSpec::TYPE_BOOL),
            'source' => new FieldSpec('source', 'Где возник недобор', FieldSpec::TYPE_ENUM, [
                ['value' => 'api', 'label' => 'Приём заказа по API'],
                ['value' => 'assembly', 'label' => 'Сборка на складе'],
            ]),
        ];
    }

    protected function ownTags(array $data): array
    {
        $tags = ['недобор:есть'];

        if ($data['is_full_cancel'] ?? false) {
            $tags[] = 'недобор:полный';
        }

        return $tags;
    }

    public function defaultSubject(): string
    {
        return 'Заказ {{order_number}}: часть позиций не набралась';
    }
}
