<?php

namespace App\Enums;

/**
 * Состояние группы совместной отгрузки на заказе (v16.11.0).
 *
 * `pending` — группа ушла в 1С, итога нет: резерв держится локально, правки и отмена
 * закрыты. `confirmed` / `conflict` — терминальный итог из order.updated.
 */
enum ShipTogetherStatus: string
{
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';
    case CONFLICT = 'conflict';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Ждём подтверждения склада',
            self::CONFIRMED => 'Отгрузка подтверждена',
            self::CONFLICT => 'Отправить вместе не удалось',
        };
    }
}
