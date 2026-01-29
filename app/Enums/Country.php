<?php

namespace App\Enums;

enum Country: string
{
    case RU = 'RU';
    case BY = 'BY';
    case KZ = 'KZ';

    public function label(): string
    {
        return match ($this) {
            self::RU => 'Россия',
            self::BY => 'Беларусь',
            self::KZ => 'Казахстан',
        };
    }
}
