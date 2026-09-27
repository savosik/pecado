<?php

namespace Tests\Feature\Pickup;

use App\Models\Pickup\PickupDeskDay;
use App\Models\Pickup\PickupDeskPause;
use App\Services\Pickup\PickupDeskSchedule;
use App\Services\Warehouse\WarehouseSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * pick-18: стойка выдачи — план по дням недели из одной таблицы плюс живые отлучки.
 *
 * Даты фиксированные (07.01.2030 — понедельник), склад пн–сб 9–21, праздников нет.
 */
class PickupDeskScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'warehouse.timezone' => 'Europe/Moscow',
            'warehouse.week' => array_fill_keys([1, 2, 3, 4, 5, 6], ['09:00', '21:00']),
            'warehouse.closed_dates' => [], 'warehouse.open_dates' => [], 'warehouse.special_hours' => [],
            'warehouse.pickup_phone' => '+7 (999) 000-00-00',
            'production_calendar.holidays' => [],
        ]);
    }

    /** Свежий сервис на каждый вызов: часы кешируются на экземпляр. */
    private function desk(): PickupDeskSchedule
    {
        WarehouseSchedule::forgetWeek();

        return new PickupDeskSchedule(new WarehouseSchedule);
    }

    private function at(string $moscow): CarbonImmutable
    {
        return CarbonImmutable::parse($moscow, 'Europe/Moscow');
    }

    private function day(int $iso, array $breaks, bool $works = true, ?string $opens = null, ?string $closes = null): void
    {
        PickupDeskDay::create(['iso_weekday' => $iso, 'works' => $works, 'opens_at' => $opens, 'closes_at' => $closes, 'breaks' => $breaks]);
    }

    /** @return list<string> */
    private function windows(string $day): array
    {
        return array_map(fn (array $w) => $w['from']->format('H:i').'–'.$w['to']->format('H:i').' '.$w['label'], $this->desk()->closedWindows($this->at($day)));
    }

    #[Test]
    public function without_rows_the_desk_is_open_all_day_by_config(): void
    {
        $this->assertSame([], $this->windows('2030-01-07'));
        $this->assertSame('open', $this->desk()->status($this->at('2030-01-07 13:30'))['state']);
        $this->assertSame('Сегодня выдают без перерывов', $this->desk()->summary($this->at('2030-01-07 13:30'))['today_text']);
        $this->assertNull($this->desk()->weekText());
    }

    #[Test]
    public function planned_break_closes_the_desk_and_is_announced_ahead(): void
    {
        $this->day(1, [['from' => '13:00', 'to' => '14:00', 'label' => 'Обед']]);

        $this->assertSame(['13:00–14:00 обед'], $this->windows('2030-01-07'));

        $break = $this->desk()->status($this->at('2030-01-07 13:30'));
        $this->assertSame('break', $break['state']);
        $this->assertSame('Перерыв до 14:00 · обед', $break['text']);

        $open = $this->desk()->status($this->at('2030-01-07 12:00'));
        $this->assertSame('open', $open['state']);
        $this->assertSame('13:00–14:00 (обед)', $open['next_break']);
        $this->assertSame('open', $this->desk()->status($this->at('2030-01-07 14:00'))['state']);
        $this->assertSame('Перерывы сегодня: 13:00–14:00 (обед)', $this->desk()->summary($this->at('2030-01-07 10:00'))['today_text']);
        $this->assertSame([], $this->windows('2030-01-08')); // вторник без строки — по конфигу
    }

    #[Test]
    public function overlapping_breaks_merge_into_one_window_with_both_labels(): void
    {
        $this->day(1, [['from' => '13:00', 'to' => '14:00', 'label' => 'обед'], ['from' => '13:30', 'to' => '14:30', 'label' => 'почта']]);

        $this->assertSame(['13:00–14:30 обед, почта'], $this->windows('2030-01-07'));
    }

    #[Test]
    public function day_row_overrides_warehouse_hours_and_day_off(): void
    {
        $this->day(1, [['from' => '12:00', 'to' => '12:30', 'label' => 'обед']], true, '10:00', '18:00');
        $this->day(6, [], false); // суббота — выходной вопреки конфигу
        $this->day(7, [], true, '11:00', '15:00'); // воскресенье — работаем вопреки конфигу

        $schedule = new WarehouseSchedule;
        $this->assertSame('10:00', $schedule->hoursFor($this->at('2030-01-07'))[0]->format('H:i'));
        $this->assertSame('18:00', $schedule->closesAt($this->at('2030-01-07'))->format('H:i'));
        $this->assertNull($schedule->hoursFor($this->at('2030-01-12')));
        $this->assertSame('11:00', $schedule->hoursFor($this->at('2030-01-13'))[0]->format('H:i'));
        $this->assertSame('пн, 10:00–18:00; вт–пт, 9:00–21:00; вс, 11:00–15:00', $schedule->weekText());
        $this->assertSame(['12:00–12:30 обед'], $this->windows('2030-01-07'));
        $this->assertSame('closed', $this->desk()->status($this->at('2030-01-12 12:00'))['state']);
    }

    #[Test]
    public function break_outside_hours_is_clipped_to_them(): void
    {
        $this->day(1, [['from' => '08:00', 'to' => '09:30', 'label' => 'приёмка'], ['from' => '20:30', 'to' => '22:00', 'label' => '']]);

        $this->assertSame(['09:00–09:30 приёмка', '20:30–21:00 '], $this->windows('2030-01-07'));
    }

    #[Test]
    public function live_pause_closes_the_desk_and_resume_shortens_it(): void
    {
        $pause = PickupDeskPause::create(['reason' => 'почта', 'started_at' => $this->at('2030-01-07 15:00'), 'until_at' => $this->at('2030-01-07 15:30')]);

        $this->assertSame(['15:00–15:30 почта'], $this->windows('2030-01-07'));
        $status = $this->desk()->status($this->at('2030-01-07 15:10'));
        $this->assertSame('break', $status['state']);
        $this->assertSame('Перерыв до 15:30 · почта', $status['text']);

        $pause->update(['ended_at' => $this->at('2030-01-07 15:12')]);
        $this->assertSame(['15:00–15:12 почта'], $this->windows('2030-01-07'));
        $this->assertSame('open', $this->desk()->status($this->at('2030-01-07 15:15'))['state']);
        $this->assertSame([], $this->desk()->closedWindows($this->at('2030-01-07'), false)); // план без отлучек
    }

    #[Test]
    public function closed_warehouse_reports_next_opening(): void
    {
        $sunday = $this->desk()->status($this->at('2030-01-13 12:00'));
        $this->assertSame('closed', $sunday['state']);
        $this->assertSame('Склад закрыт, откроется завтра в 09:00', $sunday['text']);

        $early = $this->desk()->status($this->at('2030-01-07 08:00'));
        $this->assertSame('Склад закрыт, откроется в 09:00', $early['text']);
    }

    #[Test]
    public function week_text_groups_identical_days(): void
    {
        foreach ([1, 2, 3, 4, 5] as $iso) {
            $this->day($iso, [['from' => '13:00', 'to' => '14:00', 'label' => 'обед']]);
        }

        $this->assertSame('пн–пт 13:00–14:00; сб без перерывов', $this->desk()->weekText());
        $this->assertArrayNotHasKey('pauses', $this->desk()->publicSummary($this->at('2030-01-07 10:00')));
    }
}
