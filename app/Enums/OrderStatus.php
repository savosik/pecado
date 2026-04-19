<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';
    case READY_TO_SHIP = 'ready_to_ship';
    case CLOSED = 'closed';
    case DELETED = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::PENDING       => 'Ожидает',
            self::CONFIRMED     => 'Подтверждён',
            self::READY_TO_SHIP => 'К отгрузке',
            self::CLOSED        => 'Закрыт',
            self::DELETED       => 'Удалён',
        };
    }
}
