<?php

namespace App\Enums\Crm;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Кредитный рейтинг клиента — накопленный опыт оплаты.
 */
enum CreditRating: string
{
    use HasLabeledOptions;

    case RELIABLE = 'reliable';
    case DISCIPLINED = 'disciplined';
    case PROBLEMATIC = 'problematic';
    case RISKY = 'risky';

    public function label(): string
    {
        return match ($this) {
            self::RELIABLE => 'Платит вовремя',
            self::DISCIPLINED => 'Дисциплинированный',
            self::PROBLEMATIC => 'Проблемный (задерживает)',
            self::RISKY => 'Рискованный',
        };
    }
}
