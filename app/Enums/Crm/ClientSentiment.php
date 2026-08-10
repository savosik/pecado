<?php

namespace App\Enums\Crm;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Настроение партнёра — субъективная оценка менеджера.
 *
 * Намеренно отделено от жизненного статуса (ClientLifecycleStatus): активный партнёр
 * бывает раздражён, а спящий — вполне лоялен и просто закупился впрок.
 */
enum ClientSentiment: string
{
    use HasLabeledOptions;

    case LOYAL = 'loyal';
    case NEUTRAL = 'neutral';
    case IRRITATED = 'irritated';
    case AT_RISK = 'at_risk';

    public function label(): string
    {
        return match ($this) {
            self::LOYAL => 'Лоялен',
            self::NEUTRAL => 'Нейтрален',
            self::IRRITATED => 'Раздражён',
            self::AT_RISK => 'На грани ухода',
        };
    }

    /**
     * Цвет бейджа на фронте (Chakra colorPalette).
     */
    public function color(): string
    {
        return match ($this) {
            self::LOYAL => 'green',
            self::NEUTRAL => 'gray',
            self::IRRITATED => 'orange',
            self::AT_RISK => 'red',
        };
    }
}
