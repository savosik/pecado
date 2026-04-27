<?php

namespace App\Enums;

enum ReturnStatus: string
{
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';
    case READY_TO_SHIP = 'ready_to_ship';
    case CLOSED = 'closed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Не согласована',
            self::CONFIRMED => 'Подтверждён',
            self::READY_TO_SHIP => 'К отгрузке',
            self::CLOSED => 'Выполнена',
            self::CANCELLED => 'Отклонена',
        };
    }
}
