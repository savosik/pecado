<?php

namespace App\Enums\Crm;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Ценовой сегмент клиента.
 *
 * Отсекает половину каталога ещё до подбора: премиальная позиция в эконом-точке
 * не продастся, сколько её ни предлагай.
 */
enum PriceSegment: string
{
    use HasLabeledOptions;

    case ECONOMY = 'economy';
    case MEDIUM = 'medium';
    case PREMIUM = 'premium';

    public function label(): string
    {
        return match ($this) {
            self::ECONOMY => 'Эконом',
            self::MEDIUM => 'Средний',
            self::PREMIUM => 'Премиум',
        };
    }
}
