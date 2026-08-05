<?php

namespace App\Enums\Crm;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Договорный тип оплаты.
 *
 * Это условие сделки, в отличие от {@see PaymentBehavior} — наблюдения
 * менеджера о том, как клиент платит на самом деле.
 */
enum PaymentType: string
{
    use HasLabeledOptions;

    case PREPAY = 'prepay';
    case DEFERRED = 'deferred';
    case MIXED = 'mixed';

    public function label(): string
    {
        return match ($this) {
            self::PREPAY => 'Предоплата',
            self::DEFERRED => 'Отсрочка',
            self::MIXED => 'Смешанная',
        };
    }
}
