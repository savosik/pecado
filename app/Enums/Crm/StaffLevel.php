<?php

namespace App\Enums\Crm;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Уровень продавцов на точках партнёра.
 *
 * Новичкам нужен простой ходовой товар, экспертам можно давать сложный:
 * иначе позиция зависнет на полке не по вине спроса.
 */
enum StaffLevel: string
{
    use HasLabeledOptions;

    case EXPERTS = 'experts';
    case NOVICES = 'novices';
    case MIXED = 'mixed';

    public function label(): string
    {
        return match ($this) {
            self::EXPERTS => 'Эксперты',
            self::NOVICES => 'Новички',
            self::MIXED => 'По-разному',
        };
    }
}
