<?php

namespace Tests\Unit\Warehouse;

use App\Services\Warehouse\WarehouseSchedule;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * pick-02: график склада пн–сб 9–21, отсечка 20:00, SLA 40 минут.
 *
 * Сервис чистый («сейчас» — аргумент), поэтому даты фиксированные и от календаря не протухают:
 * праздники в тесте задаются явно. 07.01.2030 — понедельник.
 */
class WarehouseScheduleTest extends TestCase
{
    private WarehouseSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'warehouse.timezone' => 'Europe/Moscow',
            'warehouse.cutoff_minutes_before_close' => 60,
            'warehouse.sla_minutes' => 40,
            'warehouse.closed_dates' => [],
            'warehouse.open_dates' => [],
            'warehouse.special_hours' => [],
            'production_calendar.holidays' => [2030 => ['2030-01-10']],
        ]);

        $this->schedule = new WarehouseSchedule;
        $this->assertSame(1, $this->at('2030-01-07 12:00')->isoWeekday());
    }

    private function at(string $moscow): CarbonImmutable
    {
        return CarbonImmutable::parse($moscow, 'Europe/Moscow');
    }

    #[Test]
    public function open_hours_cover_monday_to_saturday(): void
    {
        $this->assertTrue($this->schedule->isOpen($this->at('2030-01-07 09:00')));
        $this->assertTrue($this->schedule->isOpen($this->at('2030-01-12 20:59')), 'суббота — рабочий день склада');
        $this->assertFalse($this->schedule->isOpen($this->at('2030-01-07 21:00')));
        $this->assertFalse($this->schedule->isOpen($this->at('2030-01-07 08:59')));
        $this->assertFalse($this->schedule->isOpen($this->at('2030-01-13 12:00')), 'воскресенье — выходной');
        $this->assertSame('пн–сб, 9:00–21:00', $this->schedule->weekText());
    }

    #[Test]
    public function order_before_cutoff_is_promised_today(): void
    {
        $promised = $this->schedule->promisedReadyAt($this->at('2030-01-07 19:59'));

        $this->assertSame('2030-01-07 20:39', $promised->format('Y-m-d H:i'));
        $this->assertTrue($this->schedule->describe($this->at('2030-01-07 14:00'))['same_day']);
        $this->assertSame('Соберём к ~14:40', $this->schedule->describe($this->at('2030-01-07 14:00'))['text']);
        $this->assertSame('выдача сегодня до 21:00', $this->schedule->describe($this->at('2030-01-07 14:00'))['deadline_text']);
    }

    #[Test]
    public function order_at_or_after_cutoff_moves_to_next_opening(): void
    {
        foreach (['2030-01-07 20:00', '2030-01-07 20:01', '2030-01-07 23:30'] as $moment) {
            $this->assertSame('2030-01-08 09:40', $this->schedule->promisedReadyAt($this->at($moment))->format('Y-m-d H:i'), $moment);
        }

        $described = $this->schedule->describe($this->at('2030-01-07 20:01'));
        $this->assertFalse($described['same_day']);
        $this->assertStringContainsString('завтра к ~09:40', $described['text']);
    }

    #[Test]
    public function order_before_opening_waits_for_opening(): void
    {
        $this->assertSame('2030-01-07 09:40', $this->schedule->promisedReadyAt($this->at('2030-01-07 06:15'))->format('Y-m-d H:i'));
    }

    #[Test]
    public function saturday_evening_and_sunday_roll_over_to_monday(): void
    {
        $this->assertSame('2030-01-14 09:40', $this->schedule->promisedReadyAt($this->at('2030-01-12 20:30'))->format('Y-m-d H:i'));
        $this->assertSame('2030-01-14 09:40', $this->schedule->promisedReadyAt($this->at('2030-01-13 15:00'))->format('Y-m-d H:i'));
        $this->assertStringContainsString('завтра', $this->schedule->describe($this->at('2030-01-13 15:00'))['text']);
        $this->assertStringContainsString('в понедельник', $this->schedule->describe($this->at('2030-01-12 20:30'))['text']);
    }

    #[Test]
    public function holiday_is_closed_unless_forced_open(): void
    {
        $this->assertFalse($this->schedule->isOpen($this->at('2030-01-10 12:00')));
        $this->assertSame('2030-01-11 09:40', $this->schedule->promisedReadyAt($this->at('2030-01-09 20:30'))->format('Y-m-d H:i'), 'канун праздника');

        config(['warehouse.open_dates' => ['2030-01-10']]);
        $this->assertTrue($this->schedule->isOpen($this->at('2030-01-10 12:00')));

        config(['warehouse.open_dates' => [], 'warehouse.special_hours' => ['2030-01-10' => ['10:00', '16:00']]]);
        $this->assertSame('15:00', $this->schedule->cutoffAt($this->at('2030-01-10 12:00'))->format('H:i'));
    }

    #[Test]
    public function manual_closed_date_wins(): void
    {
        config(['warehouse.closed_dates' => ['2030-01-08']]);

        $this->assertSame('2030-01-09 09:40', $this->schedule->promisedReadyAt($this->at('2030-01-07 20:30'))->format('Y-m-d H:i'));
    }

    #[Test]
    public function cutoff_and_sla_are_configurable(): void
    {
        config(['warehouse.cutoff_minutes_before_close' => 30, 'warehouse.sla_minutes' => 25]);

        $this->assertSame('2030-01-07 20:45', $this->schedule->promisedReadyAt($this->at('2030-01-07 20:20'))->format('Y-m-d H:i'));
    }

    #[Test]
    public function pass_lifetime_ends_with_third_working_day(): void
    {
        // пт 11.01 (рабочий, склад ещё открыт) → пт, сб, пн = конец понедельника 14.01.
        $this->assertSame('2030-01-14 21:00', $this->schedule->endOfWorkingDay($this->at('2030-01-11 15:00'), 3)->format('Y-m-d H:i'));
        // после закрытия пятница уже не считается: сб, пн, вт.
        $this->assertSame('2030-01-15 21:00', $this->schedule->endOfWorkingDay($this->at('2030-01-11 21:30'), 3)->format('Y-m-d H:i'));
    }

    #[Test]
    public function input_in_other_timezone_is_converted(): void
    {
        $utc = CarbonImmutable::parse('2030-01-07 17:30', 'UTC'); // 20:30 по Москве — после отсечки

        $this->assertSame('2030-01-08 09:40', $this->schedule->promisedReadyAt($utc)->format('Y-m-d H:i'));
    }
}
