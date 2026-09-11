<?php

namespace Tests\Feature\Crm;

use App\Services\Crm\TimesheetService;
use App\Services\Payroll\Support\WorkingCalendar;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Производственный календарь 2027 (карточка mot-42): без него январские праздники
 * стали бы рабочими, а план первого квартала — завышенным примерно на треть.
 */
class ProductionCalendar2027Test extends TestCase
{
    #[Test]
    #[TestDox('Праздники и переносы 2027 года нерабочие, обычные будни рабочие')]
    public function holidays_2027_are_non_working(): void
    {
        $timesheet = app(TimesheetService::class);

        foreach (['2027-01-04', '2027-01-08', '2027-02-23', '2027-03-08', '2027-05-03', '2027-05-10', '2027-06-14', '2027-11-04'] as $day) {
            $this->assertFalse($timesheet->isWorkingDay(CarbonImmutable::parse($day)), "{$day} должен быть выходным");
        }

        foreach (['2027-01-11', '2027-02-24', '2027-05-04', '2027-11-05'] as $day) {
            $this->assertTrue($timesheet->isWorkingDay(CarbonImmutable::parse($day)), "{$day} должен быть рабочим");
        }

        $january = app(WorkingCalendar::class)->monthDays(CarbonImmutable::parse('2027-01-01'), CarbonImmutable::parse('2027-02-01'));
        $this->assertSame(15, $january['total'], 'В январе 2027 — 15 рабочих дней');
    }
}
