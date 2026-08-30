<?php

namespace App\Services\Payroll\Support;

/**
 * Числа в пояснениях расчёта — по-русски и без лишних копеек.
 */
final class Money
{
    public static function rub(float|int|string|null $value): string
    {
        $value = (float) ($value ?? 0);
        $decimals = abs($value - round($value)) < 0.005 ? 0 : 2;

        return number_format($value, $decimals, ',', ' ').' ₽';
    }

    /**
     * Доля как проценты: 0.7364 → «73,6 %».
     */
    public static function percent(float|int|null $share, int $decimals = 1): string
    {
        return number_format((float) ($share ?? 0) * 100, $decimals, ',', ' ').' %';
    }

    /**
     * Коэффициент: 0.8 → «0,8», 1.05 → «1,05».
     */
    public static function factor(float|int|null $value): string
    {
        $value = (float) ($value ?? 0);
        $decimals = abs($value * 100 - round($value * 100)) < 0.0001 ? (abs($value * 10 - round($value * 10)) < 0.0001 ? 1 : 2) : 3;

        return number_format($value, $decimals, ',', ' ');
    }

    public static function round(float $value): float
    {
        return round($value, 2);
    }
}
