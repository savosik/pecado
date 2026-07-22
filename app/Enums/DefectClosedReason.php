<?php

namespace App\Enums;

/**
 * Причина закрытия партии некондиции.
 */
enum DefectClosedReason: string
{
    /** Распродана: реализации из 1С выбрали всё количество. */
    case SOLD_OUT = 'sold_out';

    /** Списана вручную кладовщиком (утилизация, пересорт). */
    case WRITTEN_OFF = 'written_off';

    public function label(): string
    {
        return match ($this) {
            self::SOLD_OUT => 'Распродана',
            self::WRITTEN_OFF => 'Списана',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::SOLD_OUT => 'green',
            self::WRITTEN_OFF => 'gray',
        };
    }
}
