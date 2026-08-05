<?php

namespace App\Enums\Crm;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Канал продаж клиента — где он делает оборот.
 *
 * Вторичный канал помечается тем же перечнем: по нему допродажи не форсируем.
 */
enum SalesChannel: string
{
    use HasLabeledOptions;

    case OFFLINE = 'offline';
    case ONLINE = 'online';
    case MARKETPLACE = 'marketplace';

    public function label(): string
    {
        return match ($this) {
            self::OFFLINE => 'Офлайн',
            self::ONLINE => 'Онлайн',
            self::MARKETPLACE => 'Маркетплейсы',
        };
    }
}
