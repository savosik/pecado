<?php

namespace App\Enums\Crm;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Специализация товарной матрицы партнёра.
 *
 * Определяет, какую часть каталога вообще имеет смысл предлагать.
 */
enum ClientSpecialization: string
{
    use HasLabeledOptions;

    case ADULT = 'adult';
    case LINGERIE = 'lingerie';
    case COSMETICS = 'cosmetics';
    case MIXED = 'mixed';

    public function label(): string
    {
        return match ($this) {
            self::ADULT => 'Секач (adult)',
            self::LINGERIE => 'Нижнее бельё',
            self::COSMETICS => 'Косметика и уход',
            self::MIXED => 'Смешанная',
        };
    }
}
