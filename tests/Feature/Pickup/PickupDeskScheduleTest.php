<?php

namespace Tests\Feature\Pickup;

use App\Models\Pickup\PickupDeskPause;
use App\Models\Pickup\PickupDeskStaff;
use App\Services\Pickup\PickupDeskSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * pick-18: стойка выдачи закрыта, только когда на ней никого.
 *
 * Даты фиксированные (07.01.2030 — понедельник), склад пн–сб 9–21, праздников нет.
 */
class PickupDeskScheduleTest extends TestCase
{
    use RefreshDatabase;

    private PickupDeskSchedule $desk;

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

        $this->desk = app(PickupDeskSchedule::class);
    }

    private function at(string $moscow): CarbonImmutable
    {
        return CarbonImmutable::parse($moscow, 'Europe/Moscow');
    }

    private function staff(string $name, array $weekdays = [1, 2, 3, 4, 5, 6]): PickupDeskStaff
    {
        return PickupDeskStaff::create(['name' => $name, 'weekdays' => $weekdays]);
    }

    private function lunch(PickupDeskStaff $staff, string $from, string $to, string $label = 'обед', array $weekdays = [1, 2, 3, 4, 5, 6]): void
    {
        $staff->breaks()->create(['weekdays' => $weekdays, 'starts_at' => $from, 'ends_at' => $to, 'label' => $label]);
    }

    /** @return list<string> */
    private function windows(string $day): array
    {
        return array_map(fn (array $w) => $w['from']->format('H:i').'–'.$w['to']->format('H:i').' '.$w['label'], $this->desk->closedWindows($this->at($day)));
    }

    #[Test]
    public function without_staff_the_desk_is_open_all_day(): void
    {
        $this->assertSame([], $this->windows('2030-01-07'));
        $this->assertSame('open', $this->desk->status($this->at('2030-01-07 13:30'))['state']);
        $this->assertSame('Сегодня выдают без перерывов', $this->desk->summary($this->at('2030-01-07 13:30'))['today_text']);
    }

    #[Test]
    public function single_person_lunch_closes_the_desk(): void
    {
        $this->lunch($this->staff('Иванов'), '13:00', '14:00');

        $this->assertSame(['13:00–14:00 обед'], $this->windows('2030-01-07'));

        $break = $this->desk->status($this->at('2030-01-07 13:30'));
        $this->assertSame('break', $break['state']);
        $this->assertSame('Перерыв до 14:00 · обед', $break['text']);

        $open = $this->desk->status($this->at('2030-01-07 12:00'));
        $this->assertSame('open', $open['state']);
        $this->assertSame('13:00–14:00 (обед)', $open['next_break']);
        $this->assertSame('open', $this->desk->status($this->at('2030-01-07 14:00'))['state']);
        $this->assertSame('Перерывы сегодня: 13:00–14:00 (обед)', $this->desk->summary($this->at('2030-01-07 10:00'))['today_text']);
    }

    #[Test]
    public function two_people_taking_turns_keep_the_desk_open(): void
    {
        $this->lunch($this->staff('Иванов'), '13:00', '14:00');
        $this->lunch($this->staff('Петров'), '14:00', '15:00');

        $this->assertSame([], $this->windows('2030-01-07'));
        $this->assertSame('open', $this->desk->status($this->at('2030-01-07 13:30'))['state']);
    }

    #[Test]
    public function overlapping_breaks_close_the_desk_only_for_the_overlap(): void
    {
        $this->lunch($this->staff('Иванов'), '13:00', '14:00');
        $this->lunch($this->staff('Петров'), '13:30', '14:30', 'почта');

        $this->assertSame(['13:30–14:00 обед, почта'], $this->windows('2030-01-07'));
    }

    #[Test]
    public function roster_follows_weekdays_and_inactive_staff_is_ignored(): void
    {
        $this->lunch($this->staff('Иванов', [1, 2, 3, 4, 5]), '13:00', '14:00');
        $this->lunch($this->staff('Петров', [1, 2, 3, 4, 5, 6]), '15:00', '16:00');
        $this->lunch(PickupDeskStaff::create(['name' => 'Бывший', 'weekdays' => [6], 'active' => false]), '09:00', '21:00');

        $this->assertSame([], $this->windows('2030-01-07')); // пн: двое, по очереди
        $this->assertSame(['15:00–16:00 обед'], $this->windows('2030-01-12')); // сб: один Петров, его обед закрывает
        $this->assertSame([], $this->windows('2030-01-13')); // вс: склад закрыт
    }

    #[Test]
    public function live_pause_closes_the_desk_and_resume_shortens_it(): void
    {
        $staff = $this->staff('Иванов');
        $pause = PickupDeskPause::create([
            'staff_id' => $staff->id, 'reason' => 'почта',
            'started_at' => $this->at('2030-01-07 15:00'), 'until_at' => $this->at('2030-01-07 15:30'),
        ]);

        $this->assertSame(['15:00–15:30 почта'], $this->windows('2030-01-07'));
        $status = $this->desk->status($this->at('2030-01-07 15:10'));
        $this->assertSame('break', $status['state']);
        $this->assertSame('Перерыв до 15:30 · почта', $status['text']);

        $pause->update(['ended_at' => $this->at('2030-01-07 15:12')]);
        $this->assertSame(['15:00–15:12 почта'], $this->windows('2030-01-07'));
        $this->assertSame('open', $this->desk->status($this->at('2030-01-07 15:15'))['state']);
    }

    #[Test]
    public function pause_of_one_of_two_keeps_the_desk_open_but_desk_wide_pause_closes_it(): void
    {
        $ivanov = $this->staff('Иванов');
        $this->staff('Петров');
        PickupDeskPause::create(['staff_id' => $ivanov->id, 'reason' => 'обед', 'started_at' => $this->at('2030-01-07 13:00'), 'until_at' => $this->at('2030-01-07 14:00')]);

        $this->assertSame([], $this->windows('2030-01-07'));
        $summary = $this->desk->summary($this->at('2030-01-07 13:30'));
        $this->assertSame('open', $summary['state']);
        $this->assertCount(1, $summary['pauses']);
        $this->assertSame('Иванов', $summary['pauses'][0]['staff_name']);

        PickupDeskPause::create(['staff_id' => null, 'reason' => 'приёмка', 'started_at' => $this->at('2030-01-07 16:00'), 'until_at' => $this->at('2030-01-07 16:20')]);
        $this->assertSame(['16:00–16:20 приёмка'], $this->windows('2030-01-07'));
    }

    #[Test]
    public function closed_warehouse_reports_next_opening(): void
    {
        $sunday = $this->desk->status($this->at('2030-01-13 12:00'));
        $this->assertSame('closed', $sunday['state']);
        $this->assertSame('Склад закрыт, откроется завтра в 09:00', $sunday['text']);

        $early = $this->desk->status($this->at('2030-01-07 08:00'));
        $this->assertSame('Склад закрыт, откроется в 09:00', $early['text']);
    }

    #[Test]
    public function week_text_groups_identical_days(): void
    {
        $this->assertNull($this->desk->weekText());

        $this->lunch($this->staff('Иванов'), '13:00', '14:00', 'обед', [1, 2, 3, 4, 5]);
        $this->assertSame('пн–пт 13:00–14:00; сб без перерывов', $this->desk->weekText());
    }
}
