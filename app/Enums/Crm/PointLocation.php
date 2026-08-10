<?php

namespace App\Enums\Crm;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Где стоят точки партнёра.
 *
 * Локация объясняет и трафик, и чек: у вокзала берут импульсно, в спальном
 * районе — обдуманно.
 */
enum PointLocation: string
{
    use HasLabeledOptions;

    case TRANSPORT_HUB = 'transport_hub';
    case RESIDENTIAL = 'residential';
    case MALL = 'mall';
    case STREET_RETAIL = 'street_retail';
    case INDUSTRIAL = 'industrial';

    public function label(): string
    {
        return match ($this) {
            self::TRANSPORT_HUB => 'Вокзалы и транспортные узлы',
            self::RESIDENTIAL => 'Спальные районы',
            self::MALL => 'Торговые центры',
            self::STREET_RETAIL => 'Проходимый стрит-ритейл',
            self::INDUSTRIAL => 'Промзона',
        };
    }
}
