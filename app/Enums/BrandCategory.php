<?php

namespace App\Enums;

enum BrandCategory: string
{
    case Own = 'own';
    case Other = 'other';
    case Liquidation = 'liquidation';

    public function label(): string
    {
        return match ($this) {
            self::Own => 'Собственные бренды',
            self::Other => 'Прочие бренды',
            self::Liquidation => 'Ликвидация',
        };
    }
}
