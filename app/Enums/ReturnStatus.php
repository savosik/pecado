<?php

namespace App\Enums;

/**
 * Справочник статусов заявки на возврат товаров от клиента.
 * Источник истины — перечисление 1С:КА2 (см. docs-erp/content/rules/returns.md).
 *
 * Английские ключи передаются по шине RabbitMQ в поле `status` сообщения
 * `return.updated`. Маппинг русских названий из 1С — в
 * `App\Services\Erp\Support\ReturnStatusMapper`.
 */
enum ReturnStatus: string
{
    case PENDING_APPROVAL = 'pending_approval';
    case FOR_RETURN = 'for_return';
    case IN_RESERVE = 'in_reserve';
    case READY_FOR_SHIPMENT = 'ready_for_shipment';
    case COMPLETED = 'completed';
    case REJECTED = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PENDING_APPROVAL => 'На согласовании',
            self::FOR_RETURN => 'К возврату',
            self::IN_RESERVE => 'В резерве',
            self::READY_FOR_SHIPMENT => 'К отгрузке',
            self::COMPLETED => 'Выполнена',
            self::REJECTED => 'Отклонена',
        };
    }

    /**
     * Цвет бейджа на фронте (Chakra colorPalette).
     */
    public function color(): string
    {
        return match ($this) {
            self::PENDING_APPROVAL => 'yellow',
            self::FOR_RETURN => 'orange',
            self::IN_RESERVE => 'blue',
            self::READY_FOR_SHIPMENT => 'purple',
            self::COMPLETED => 'green',
            self::REJECTED => 'red',
        };
    }
}
