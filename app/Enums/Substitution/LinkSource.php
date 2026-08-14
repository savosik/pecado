<?php

namespace App\Enums\Substitution;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Откуда связь замены появилась в справочнике.
 *
 * Источник определяет доверие: manual активна сразу, learned и ai требуют
 * подтверждения человеком, прежде чем автоподбор начнёт их предлагать.
 */
enum LinkSource: string
{
    use HasLabeledOptions;

    case MANUAL = 'manual';
    case LEARNED = 'learned';
    case AI = 'ai';

    public function label(): string
    {
        return match ($this) {
            self::MANUAL => 'Менеджер',
            self::LEARNED => 'Выбор клиента',
            self::AI => 'ИИ-предразметка',
        };
    }
}
