<?php

namespace App\Enums\Crm;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Вид бизнеса партнёра.
 *
 * 1С видит юрлицо, но не видит, розница это или опт: от вида бизнеса зависит
 * и ассортимент, и разговор.
 *
 * «Селлер» — тот, кто торгует только на чужих маркетплейсах, и «Массмаркет» —
 * федеральная непрофильная сеть (Яндекс.Лавка, Магнит): ни то, ни другое не
 * сводится к опту или сети, поэтому оба вида заведены отдельными.
 */
enum BusinessType: string
{
    use HasLabeledOptions;

    case OFFLINE = 'offline';
    case ONLINE = 'online';
    case CHAIN = 'chain';
    case WHOLESALE = 'wholesale';
    case SELLER = 'seller';
    case MASS_MARKET = 'mass_market';

    public function label(): string
    {
        return match ($this) {
            self::OFFLINE => 'Офлайн-розница',
            self::ONLINE => 'Онлайн-магазин',
            self::CHAIN => 'Сеть',
            self::WHOLESALE => 'Опт',
            self::SELLER => 'Селлер',
            self::MASS_MARKET => 'Массмаркет',
        };
    }
}
