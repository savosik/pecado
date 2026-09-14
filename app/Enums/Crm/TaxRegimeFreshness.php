<?php

namespace App\Enums\Crm;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Состояние ответа менеджера о налоговом режиме юрлица.
 *
 * Правило устаревания — {@see \App\Models\CrmContractorTaxRegime::freshUntil()}.
 */
enum TaxRegimeFreshness: string
{
    use HasLabeledOptions;

    case MISSING = 'missing';
    case OUTDATED = 'outdated';
    case FRESH = 'fresh';

    public function label(): string
    {
        return match ($this) {
            self::MISSING => 'Не заполнено',
            self::OUTDATED => 'Нужно подтвердить',
            self::FRESH => 'Актуально',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::MISSING => 'red',
            self::OUTDATED => 'orange',
            self::FRESH => 'green',
        };
    }
}
