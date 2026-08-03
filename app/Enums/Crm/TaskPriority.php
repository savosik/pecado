<?php

namespace App\Enums\Crm;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Приоритет задачи менеджера.
 */
enum TaskPriority: string
{
    use HasLabeledOptions;

    case LOW = 'low';
    case NORMAL = 'normal';
    case HIGH = 'high';

    public function label(): string
    {
        return match ($this) {
            self::LOW => 'Низкий',
            self::NORMAL => 'Обычный',
            self::HIGH => 'Высокий',
        };
    }

    /**
     * Цвет бейджа на фронте (Chakra colorPalette).
     */
    public function color(): string
    {
        return match ($this) {
            self::LOW => 'gray',
            self::NORMAL => 'blue',
            self::HIGH => 'red',
        };
    }

    /**
     * Вес для сортировки «сначала важное»: в БД приоритет хранится строкой,
     * и алфавитный порядок ('high', 'low', 'normal') смысла не имеет.
     */
    public function weight(): int
    {
        return match ($this) {
            self::HIGH => 3,
            self::NORMAL => 2,
            self::LOW => 1,
        };
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
