<?php

namespace App\Enums\Crm;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Тип отсутствия менеджера отдела продаж.
 *
 * Прогул отделён от отгула намеренно: отгул — согласованное отсутствие,
 * прогул — нарушение, которое РОП фиксирует задним числом и видит в итогах табеля.
 */
enum ManagerAbsenceType: string
{
    use HasLabeledOptions;

    case VACATION = 'vacation';
    case DAY_OFF = 'day_off';
    case SICK_LEAVE = 'sick_leave';
    case TRUANCY = 'truancy';

    public function label(): string
    {
        return match ($this) {
            self::VACATION => 'Отпуск',
            self::DAY_OFF => 'Отгул',
            self::SICK_LEAVE => 'Больничный',
            self::TRUANCY => 'Прогул',
        };
    }

    /**
     * Код клетки табеля — буквенные коды формы Т-13, привычные бухгалтерии.
     */
    public function timesheetCode(): string
    {
        return match ($this) {
            self::VACATION => 'ОТ',
            self::DAY_OFF => 'ОД',
            self::SICK_LEAVE => 'Б',
            self::TRUANCY => 'ПР',
        };
    }

    /**
     * Цвет бейджа на фронте (Chakra colorPalette).
     */
    public function color(): string
    {
        return match ($this) {
            self::VACATION => 'blue',
            self::DAY_OFF => 'gray',
            self::SICK_LEAVE => 'orange',
            self::TRUANCY => 'red',
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
