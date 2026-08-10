<?php

namespace App\Enums\Crm;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Стиль общения партнёра.
 *
 * Задаёт форму разговора и письма: «охотнику за скидками» — сразу цифра
 * выгоды, «коротко и по делу» — три фразы без прелюдий.
 */
enum Psychotype: string
{
    use HasLabeledOptions;

    case BRIEF = 'brief';
    case TALKATIVE = 'talkative';
    case DISCOUNT_HUNTER = 'discount_hunter';
    case TOUGH = 'tough';

    public function label(): string
    {
        return match ($this) {
            self::BRIEF => 'Коротко и по делу',
            self::TALKATIVE => 'Любит поговорить',
            self::DISCOUNT_HUNTER => 'Охотник за скидками',
            self::TOUGH => 'Жёсткий в переговорах',
        };
    }
}
