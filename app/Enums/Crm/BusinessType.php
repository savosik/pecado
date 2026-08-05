<?php

namespace App\Enums\Crm;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Вид бизнеса клиента.
 *
 * 1С видит юрлицо, но не видит, розница это или опт: от вида бизнеса зависит
 * и ассортимент, и разговор.
 */
enum BusinessType: string
{
    use HasLabeledOptions;

    case OFFLINE = 'offline';
    case ONLINE = 'online';
    case CHAIN = 'chain';
    case WHOLESALE = 'wholesale';

    public function label(): string
    {
        return match ($this) {
            self::OFFLINE => 'Офлайн-розница',
            self::ONLINE => 'Онлайн-магазин',
            self::CHAIN => 'Сеть',
            self::WHOLESALE => 'Опт',
        };
    }
}
