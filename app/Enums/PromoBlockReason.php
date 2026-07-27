<?php

namespace App\Enums;

/**
 * Почему сработавшее правило акции не выдало промо-позицию.
 *
 * Диагностика для админского предпросмотра — клиенту эти причины не показываем
 * (см. docs/promo-constructor-roadmap.md: «нет остатка — нет промо-позиции»,
 * клиенту про это не сообщаем).
 */
enum PromoBlockReason: string
{
    /** Не хватает остатка на складе-источнике. */
    case OUT_OF_STOCK = 'out_of_stock';

    /** Исчерпан лимит выдачи на одного клиента. */
    case CLIENT_LIMIT = 'client_limit';

    /** Исчерпан общий лимит выдачи. */
    case TOTAL_LIMIT = 'total_limit';

    /** Правило пока в режиме показа: условие выполнено, но выдача не включена. */
    case NOT_ACTIVE_YET = 'not_active_yet';

    /** Правило не работает в этом канале (сайт/клиентское API). */
    case WRONG_CHANNEL = 'wrong_channel';

    public function label(): string
    {
        return match ($this) {
            self::OUT_OF_STOCK => 'Нет остатка на складе',
            self::CLIENT_LIMIT => 'Исчерпан лимит на клиента',
            self::TOTAL_LIMIT => 'Исчерпан общий лимит',
            self::NOT_ACTIVE_YET => 'Правило в режиме показа, выдача не включена',
            self::WRONG_CHANNEL => 'Правило не работает в этом канале',
        };
    }
}
