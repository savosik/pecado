<?php

namespace App\Services\Payroll\Support;

use App\Services\Crm\TimesheetService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Рабочие дни для зарплаты — тот же производственный календарь, что у табеля.
 *
 * Ступени штрафа считаются в рабочих днях, прогноз — по прошедшим и оставшимся
 * рабочим дням месяца. Своего списка праздников нет: `config/production_calendar.php`
 * через {@see TimesheetService::isWorkingDay()}.
 */
class WorkingCalendar
{
    public function __construct(private readonly TimesheetService $timesheet) {}

    public function isWorkingDay(CarbonInterface $date): bool
    {
        return $this->timesheet->isWorkingDay($date);
    }

    /**
     * Рабочих дней в полуинтервале (после, до]: сколько рабочих дней прошло
     * от срока оплаты до платежа. Платёж в срок или раньше — ноль.
     */
    public function workingDaysBetween(CarbonInterface $after, CarbonInterface $until): int
    {
        $from = CarbonImmutable::instance($after)->startOfDay()->addDay();
        $to = CarbonImmutable::instance($until)->startOfDay();

        $count = 0;
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            if ($this->isWorkingDay($day)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Рабочие дни месяца: всего, прошло (включая сегодня), осталось.
     *
     * @return array{total: int, passed: int, left: int}
     */
    public function monthDays(CarbonInterface $month, ?CarbonInterface $today = null): array
    {
        $start = CarbonImmutable::instance($month)->startOfMonth();
        $end = $start->endOfMonth()->startOfDay();
        $now = CarbonImmutable::instance($today ?? now())->startOfDay();

        $total = 0;
        $passed = 0;

        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            if (! $this->isWorkingDay($day)) {
                continue;
            }

            $total++;

            if ($day->lte($now)) {
                $passed++;
            }
        }

        return ['total' => $total, 'passed' => $passed, 'left' => max(0, $total - $passed)];
    }
}
