<?php

namespace App\Enums;

/**
 * Ступень лестницы долга (эпик debt-00 v2).
 *
 * Пять состояний по возрасту просрочки; других сигналов нет намеренно —
 * каждая ступень объясняется одной фразой. Порядок кейсов = порядок
 * ужесточения, `rank()` опирается на него.
 */
enum DebtLevel: string
{
    case CLEAN = 'clean';
    case OVERDUE = 'overdue';
    case NO_PREORDERS = 'no_preorders';
    case NO_ORDERS = 'no_orders';
    case HOLD = 'hold';

    public function label(): string
    {
        return match ($this) {
            self::CLEAN => 'Чисто',
            self::OVERDUE => 'Просрочка',
            self::NO_PREORDERS => 'Предзаказы закрыты',
            self::NO_ORDERS => 'Заказы закрыты',
            self::HOLD => 'Стоп-отгрузка',
        };
    }

    /** Что это значит для клиента — одной фразой, деловым тоном. */
    public function clientHint(): string
    {
        return match ($this) {
            self::CLEAN => '',
            self::OVERDUE => 'Есть просроченные оплаты.',
            self::NO_PREORDERS => 'Оформление предзаказов приостановлено до погашения просрочки.',
            self::NO_ORDERS => 'Оформление заказов от этого контрагента приостановлено до погашения просрочки.',
            self::HOLD => 'Оформление заказов приостановлено до погашения задолженности.',
        };
    }

    /** Палитра Chakra для бейджа. */
    public function color(): string
    {
        return match ($this) {
            self::CLEAN => 'green',
            self::OVERDUE => 'yellow',
            self::NO_PREORDERS => 'orange',
            self::NO_ORDERS => 'red',
            self::HOLD => 'red',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::CLEAN => 0,
            self::OVERDUE => 1,
            self::NO_PREORDERS => 2,
            self::NO_ORDERS => 3,
            self::HOLD => 4,
        };
    }

    public static function fromRank(int $rank): self
    {
        foreach (self::cases() as $case) {
            if ($case->rank() === $rank) {
                return $case;
            }
        }

        return $rank <= 0 ? self::CLEAN : self::HOLD;
    }

    public function isWorseThan(self $other): bool
    {
        return $this->rank() > $other->rank();
    }

    public function blocksPreorders(): bool
    {
        return $this->rank() >= self::NO_PREORDERS->rank();
    }

    public function blocksOrders(): bool
    {
        return $this->rank() >= self::NO_ORDERS->rank();
    }

    /** Ступень, о которой клиента предупреждают и которую показывают в кабинете. */
    public function isVisible(): bool
    {
        return $this !== self::CLEAN;
    }

    /**
     * Худшая из двух — так считается ступень партнёра по его контрагентам.
     */
    public static function worst(self $a, self $b): self
    {
        return $a->rank() >= $b->rank() ? $a : $b;
    }

    /**
     * @return list<array{value: string, label: string, color: string}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
            'color' => $case->color(),
        ], self::cases());
    }
}
