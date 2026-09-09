<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Понедельничная рассылка актов сверки стоит в расписании, но придержана флагом
 * MAIL_WEEKLY_RECONCILIATION_SCHEDULED: это письма клиентам, и после оживления
 * планировщика 2026-09-09 первый понедельник разослал бы их всем должникам разом.
 */
class WeeklyReconciliationScheduleGateTest extends TestCase
{
    public function test_weekly_reconciliation_is_scheduled_on_mondays(): void
    {
        $event = $this->weeklyReconciliationEvent();

        $this->assertSame('0 9 * * 1', $event->expression);
    }

    public function test_weekly_reconciliation_is_held_back_by_default(): void
    {
        config(['mail_stream.weekly_reconciliation_scheduled' => false]);

        $this->assertFalse($this->weeklyReconciliationEvent()->filtersPass($this->app));
    }

    public function test_weekly_reconciliation_runs_when_flag_is_on(): void
    {
        config(['mail_stream.weekly_reconciliation_scheduled' => true]);

        $this->assertTrue($this->weeklyReconciliationEvent()->filtersPass($this->app));
    }

    private function weeklyReconciliationEvent(): Event
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn (Event $event) => str_contains($event->command ?? '', 'mail:weekly-reconciliation'));

        $this->assertCount(1, $events, 'mail:weekly-reconciliation должна стоять в расписании ровно один раз');

        return $events->first();
    }
}
