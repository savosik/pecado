<?php

namespace App\Services\Payroll\Components;

use App\Services\Payroll\Contracts\PayrollComponent;

/**
 * Общее для компонентов: доменных проверок по умолчанию нет.
 */
abstract class AbstractComponent implements PayrollComponent
{
    public function validateParams(array $params): array
    {
        return [];
    }

    /**
     * Число из параметров с умолчанием — параметры приходят из JSON и могут быть строками.
     *
     * @param  array<string, mixed>  $params
     */
    protected function number(array $params, string $key, float $default = 0.0): float
    {
        $value = $params[$key] ?? null;

        return is_numeric($value) ? (float) $value : $default;
    }
}
