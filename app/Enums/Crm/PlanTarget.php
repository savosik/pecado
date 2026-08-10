<?php

namespace App\Enums\Crm;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Кому поставлен месячный план продаж.
 *
 * Три уровня, а не два: план отдела не выводится суммой планов менеджеров и наоборот.
 * Руководитель может поставить отделу цифру больше суммы планов менеджеров (запас
 * на новых партнёров), а менеджер — расписать свою цифру по партнёрам не полностью.
 * Расхождение показывается подсказкой, но не запрещается — жизнь сложнее арифметики.
 */
enum PlanTarget: string
{
    use HasLabeledOptions;

    case DEPARTMENT = 'department';
    case MANAGER = 'manager';
    case CLIENT = 'client';

    public function label(): string
    {
        return match ($this) {
            self::DEPARTMENT => 'Отдел',
            self::MANAGER => 'Менеджер',
            self::CLIENT => 'Партнёр',
        };
    }

    /**
     * Нужен ли этому типу идентификатор цели.
     *
     * У отдела цели нет: он в системе один, и target_id для него всегда NULL.
     */
    public function needsTarget(): bool
    {
        return $this !== self::DEPARTMENT;
    }

    /**
     * Таблица, на которую указывает target_id. Для отдела — null.
     */
    public function targetTable(): ?string
    {
        return match ($this) {
            self::DEPARTMENT => null,
            self::MANAGER => 'personal_managers',
            self::CLIENT => 'users',
        };
    }
}
