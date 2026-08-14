<?php

namespace App\Enums\Substitution;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Характер связи «этот товар можно предложить вместо того».
 *
 * Связь направленная: downgrade (дешевле/проще вместо дорогого) клиент обычно
 * принимает, upgrade в обратную сторону — далеко не всегда, поэтому пары
 * заводятся по направлению from → to, а не симметрично.
 */
enum LinkKind: string
{
    use HasLabeledOptions;

    case VARIANT = 'variant';
    case LINE = 'line';
    case EQUIVALENT = 'equivalent';
    case DOWNGRADE = 'downgrade';
    case UPGRADE = 'upgrade';
    case ANALOG_VOLUME = 'analog_volume';

    public function label(): string
    {
        return match ($this) {
            self::VARIANT => 'Вариант (цвет/размер)',
            self::LINE => 'Соседнее поколение линейки',
            self::EQUIVALENT => 'Полный аналог',
            self::DOWNGRADE => 'Проще и дешевле',
            self::UPGRADE => 'Дороже и функциональнее',
            self::ANALOG_VOLUME => 'Аналог другого объёма/фасовки',
        };
    }
}
