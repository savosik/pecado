<?php

namespace App\Enums\Crm;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Как клиент получает товар.
 */
enum DeliveryMethod: string
{
    use HasLabeledOptions;

    case PICKUP = 'pickup';
    case WE_DELIVER = 'we_deliver';
    case CARRIER = 'carrier';
    case MIXED = 'mixed';

    public function label(): string
    {
        return match ($this) {
            self::PICKUP => 'Самовывоз',
            self::WE_DELIVER => 'Везём мы',
            self::CARRIER => 'Транспортная компания',
            self::MIXED => 'По-разному',
        };
    }
}
