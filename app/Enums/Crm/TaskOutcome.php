<?php

namespace App\Enums\Crm;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Исход закрытия задачи.
 *
 * «Перенести» исходом не является намеренно: перенос не закрывает задачу,
 * а сдвигает срок — это движение по той же задаче, а не её итог.
 */
enum TaskOutcome: string
{
    use HasLabeledOptions;

    case SUCCESS = 'success';
    case PROBLEM = 'problem';

    public function label(): string
    {
        return match ($this) {
            self::SUCCESS => 'Успешно',
            self::PROBLEM => 'С проблемой',
        };
    }

    /**
     * Цвет бейджа на фронте (Chakra colorPalette).
     */
    public function color(): string
    {
        return match ($this) {
            self::SUCCESS => 'green',
            self::PROBLEM => 'orange',
        };
    }

    /**
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
