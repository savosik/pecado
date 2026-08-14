<?php

namespace App\Enums\Substitution;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Сигнал по кандидату замены — обучающая выборка для тюнинга слоёв.
 *
 * Плоский лог: снятая менеджером галочка и пропуск клиентом — аргументы против
 * пары товаров, согласие клиента — за. Копится с первого дня, читается позже.
 */
enum SignalEvent: string
{
    use HasLabeledOptions;

    case MANAGER_REMOVED = 'manager_removed';
    case CLIENT_CHOSEN = 'client_chosen';
    case CLIENT_SKIPPED = 'client_skipped';

    public function label(): string
    {
        return match ($this) {
            self::MANAGER_REMOVED => 'Менеджер снял кандидата',
            self::CLIENT_CHOSEN => 'Клиент выбрал',
            self::CLIENT_SKIPPED => 'Клиент пропустил',
        };
    }
}
