<?php

namespace App\Enums\Substitution;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Жизненный цикл подборки замен по заказу.
 *
 * «Просрочена» и «закрыта без замены» разделены намеренно: первая — клиент
 * не отреагировал, вторая — менеджер осознанно решил, что замена не нужна.
 * В воронке это разные исходы.
 */
enum OfferStatus: string
{
    use HasLabeledOptions;

    case PENDING = 'pending';
    case VIEWED = 'viewed';
    case CONFIRMED = 'confirmed';
    case EXPIRED = 'expired';
    case DISMISSED = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Ожидает',
            self::VIEWED => 'Просмотрена',
            self::CONFIRMED => 'Согласована',
            self::EXPIRED => 'Просрочена',
            self::DISMISSED => 'Закрыта без замены',
        };
    }

    /**
     * Оффер ещё живой: его можно дополнять новыми строками и показывать клиенту.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::PENDING, self::VIEWED], true);
    }
}
