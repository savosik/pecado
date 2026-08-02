<?php

namespace App\Enums\Crm;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Как клиент обычно платит — наблюдение менеджера, а не факт из 1С.
 *
 * Взаиморасчёты в 1С показывают, что происходит по документам; это поле отвечает
 * на вопрос «чего от него ждать в следующей сделке» и заполняется руками.
 */
enum PaymentBehavior: string
{
    use HasLabeledOptions;

    case PREPAY = 'prepay';
    case ON_DELIVERY = 'on_delivery';
    case DEFERRED = 'deferred';
    case MIXED = 'mixed';
    case PROBLEMATIC = 'problematic';

    public function label(): string
    {
        return match ($this) {
            self::PREPAY => 'Предоплата',
            self::ON_DELIVERY => 'По факту поставки',
            self::DEFERRED => 'Отсрочка',
            self::MIXED => 'По-разному',
            self::PROBLEMATIC => 'Задерживает оплату',
        };
    }

    /**
     * Цвет бейджа на фронте (Chakra colorPalette).
     */
    public function color(): string
    {
        return match ($this) {
            self::PREPAY => 'green',
            self::ON_DELIVERY => 'teal',
            self::DEFERRED => 'blue',
            self::MIXED => 'gray',
            self::PROBLEMATIC => 'red',
        };
    }
}
