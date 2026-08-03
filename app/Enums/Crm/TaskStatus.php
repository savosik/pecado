<?php

namespace App\Enums\Crm;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Состояние задачи менеджера.
 *
 * «Отменена» отделена от «выполнена» намеренно: в отчёте о сроках это разные вещи —
 * задача, которая отпала, не должна считаться закрытой работой.
 */
enum TaskStatus: string
{
    use HasLabeledOptions;

    case OPEN = 'open';
    case IN_PROGRESS = 'in_progress';
    case DONE = 'done';
    case CANCELED = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Открыта',
            self::IN_PROGRESS => 'В работе',
            self::DONE => 'Выполнена',
            self::CANCELED => 'Отменена',
        };
    }

    /**
     * Цвет бейджа на фронте (Chakra colorPalette).
     */
    public function color(): string
    {
        return match ($this) {
            self::OPEN => 'blue',
            self::IN_PROGRESS => 'orange',
            self::DONE => 'green',
            self::CANCELED => 'gray',
        };
    }

    /**
     * Задача ещё требует действий.
     */
    public function isOpen(): bool
    {
        return in_array($this, self::activeStates(), true);
    }

    /**
     * Статусы, при которых задача считается незакрытой.
     *
     * По ним считаются просрочка, виджет «на сегодня» и напоминания: закрытая
     * или отменённая задача не должна всплывать как просроченная.
     *
     * @return list<self>
     */
    public static function activeStates(): array
    {
        return [self::OPEN, self::IN_PROGRESS];
    }

    /**
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return array_map(fn (self $case): string => $case->value, self::activeStates());
    }

    /**
     * Варианты для фронта вместе с цветом бейджа.
     *
     * @return list<array{value: string, label: string, color: string}>
     */
    public static function optionsWithColor(): array
    {
        return array_map(
            fn (self $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
                'color' => $case->color(),
            ],
            self::cases(),
        );
    }
}
