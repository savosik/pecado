<?php

namespace App\Enums;

enum Country: string
{
    case RU = 'RU'; // Россия
    case BY = 'BY'; // Беларусь
    case KZ = 'KZ'; // Казахстан
    case UA = 'UA'; // Украина
    case UZ = 'UZ'; // Узбекистан
    case AZ = 'AZ'; // Азербайджан
    case AM = 'AM'; // Армения
    case GE = 'GE'; // Грузия
    case KG = 'KG'; // Кыргызстан
    case MD = 'MD'; // Молдова
    case TJ = 'TJ'; // Таджикистан
    case TM = 'TM'; // Туркменистан

    public function label(): string
    {
        return match ($this) {
            self::RU => 'Россия',
            self::BY => 'Беларусь',
            self::KZ => 'Казахстан',
            self::UA => 'Украина',
            self::UZ => 'Узбекистан',
            self::AZ => 'Азербайджан',
            self::AM => 'Армения',
            self::GE => 'Грузия',
            self::KG => 'Кыргызстан',
            self::MD => 'Молдова',
            self::TJ => 'Таджикистан',
            self::TM => 'Туркменистан',
        };
    }
}
