<?php

namespace App\Services\Payroll\Components;

use App\Services\Payroll\Dto\ComponentResult;
use App\Services\Payroll\Dto\PayrollContext;

/**
 * Корректировка РОПа — плюс или минус с основанием, вне формулы.
 *
 * Для случаев, которые формула не предусмотрела (компенсация, удержание).
 * Отдельным компонентом, а не правкой снимка: каждая корректировка — строка
 * с автором и комментарием, и в разборе она видна отдельной строкой.
 */
class ManualCorrectionComponent extends ExtraIncomeComponent
{
    public function key(): string
    {
        return 'manual_correction';
    }

    public function label(): string
    {
        return 'Корректировка';
    }

    public function description(): string
    {
        return 'Доплата или удержание от руководителя — то, что формула не предусматривает. Всегда с основанием.';
    }

    public function howComputed(): string
    {
        return 'Сумма корректировок за месяц; отрицательная уменьшает доход.';
    }

    public function compute(PayrollContext $context, array $params): ComponentResult
    {
        return $this->sumItems($context->inputs->corrections, 'Корректировка');
    }
}
