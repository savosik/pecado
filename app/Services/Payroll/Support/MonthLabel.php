<?php

namespace App\Services\Payroll\Support;

use Carbon\CarbonInterface;

/**
 * Месяц по-русски для экранов зарплаты: «Август 2026».
 *
 * Локаль приложения — `en`, а переключать Carbon ради одной подписи незачем.
 */
final class MonthLabel
{
    /** @var array<int, string> */
    private const MONTHS = [
        1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель', 5 => 'Май', 6 => 'Июнь',
        7 => 'Июль', 8 => 'Август', 9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
    ];

    /** @var array<int, string> */
    private const MONTHS_GENITIVE = [
        1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля', 5 => 'мая', 6 => 'июня',
        7 => 'июля', 8 => 'августа', 9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря',
    ];

    public static function ru(CarbonInterface $month): string
    {
        return self::MONTHS[$month->month].' '.$month->year;
    }

    /**
     * «15 августа» — для подписей дат.
     */
    public static function day(CarbonInterface $date): string
    {
        return $date->day.' '.self::MONTHS_GENITIVE[$date->month];
    }
}
