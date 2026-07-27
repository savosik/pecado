<?php

namespace App\Enums;

/**
 * Режим работы правила акции.
 *
 * Волна 1 конструктора акций работает целиком в `info`: правило срабатывает
 * и показывается, но промо-позиции в корзину не попадают. Переключение в `issue`
 * становится осмысленным только после волны 2 (см. docs/promo-constructor-roadmap.md).
 */
enum PromotionRuleMode: string
{
    /** Только информируем: правило срабатывает, промо-позиции не выдаются. */
    case INFO = 'info';

    /** Выдаём промо-позиции в корзину и заказ. */
    case ISSUE = 'issue';

    public function label(): string
    {
        return match ($this) {
            self::INFO => 'Только показ',
            self::ISSUE => 'Выдача промо-позиций',
        };
    }
}
