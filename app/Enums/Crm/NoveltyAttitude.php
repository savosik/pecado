<?php

namespace App\Enums\Crm;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Отношение клиента к новинкам.
 *
 * Консерватору новинку предлагаем последней, инноватору — первой.
 */
enum NoveltyAttitude: string
{
    use HasLabeledOptions;

    case CONSERVATIVE = 'conservative';
    case INNOVATOR = 'innovator';

    public function label(): string
    {
        return match ($this) {
            self::CONSERVATIVE => 'Консерватор',
            self::INNOVATOR => 'Инноватор',
        };
    }
}
