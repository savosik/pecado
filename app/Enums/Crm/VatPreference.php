<?php

namespace App\Enums\Crm;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Насколько клиенту важно, с НДС товар или без.
 *
 * Режим налогообложения говорит, может ли клиент принять НДС к вычету; этот
 * ответ — будет ли он из-за этого искать другого поставщика. «Принципиально»
 * у плательщика с вычетами — главный риск потери объёма.
 */
enum VatPreference: string
{
    use HasLabeledOptions;

    case REQUIRED = 'required';
    case PREFERRED = 'preferred';
    case INDIFFERENT = 'indifferent';
    case WITHOUT = 'without';

    /**
     * Подпись — вопрос клиенту, поэтому от первого лица.
     */
    public function label(): string
    {
        return match ($this) {
            self::REQUIRED => 'Нужен товар с НДС — берём к вычету',
            self::PREFERRED => 'С НДС удобнее, но не принципиально',
            self::INDIFFERENT => 'Без разницы',
            self::WITHOUT => 'Лучше без НДС',
        };
    }

    /**
     * Короткая подпись для CRM.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::REQUIRED => 'НДС принципиален',
            self::PREFERRED => 'НДС желателен',
            self::INDIFFERENT => 'НДС не важен',
            self::WITHOUT => 'Лучше без НДС',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::REQUIRED => 'red',
            self::PREFERRED => 'orange',
            self::INDIFFERENT => 'gray',
            self::WITHOUT => 'blue',
        };
    }
}
