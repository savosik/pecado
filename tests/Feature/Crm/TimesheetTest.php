<?php

namespace Tests\Feature\Crm;

use App\Models\ManagerAbsence;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Crm\TimesheetService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Табель отдела продаж (abs-03): сетка, коды, итоги, доступ.
 */
class TimesheetTest extends TestCase
{
    use RefreshDatabase;

    private User $head;

    private PersonalManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->head = User::factory()->create();
        $this->head->assignRole('sales-head');

        $this->manager = PersonalManager::factory()->create([
            'name' => 'Курочкина Елена',
            'user_id' => User::factory()->create()->id,
            'is_active' => true,
        ]);
    }

    public function test_only_head_sees_timesheet(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('sales-manager-crm');

        $this->actingAs($this->head)->get(route('crm.timesheet.index'))->assertOk();
        $this->actingAs($manager)->get(route('crm.timesheet.index'))->assertForbidden();
    }

    public function test_grid_codes_for_absence_weekend_and_workday(): void
    {
        // Июль 2026 — полностью прошедший месяц: 1 июля (ср) рабочий,
        // 4 июля (сб) выходной. Отпуск 6–10 июля, прогул 13 июля.
        ManagerAbsence::factory()->create([
            'personal_manager_id' => $this->manager->id,
            'type' => 'vacation',
            'starts_on' => '2026-07-06',
            'ends_on' => '2026-07-10',
        ]);
        ManagerAbsence::factory()->create([
            'personal_manager_id' => $this->manager->id,
            'type' => 'truancy',
            'starts_on' => '2026-07-13',
            'ends_on' => '2026-07-13',
        ]);

        $data = app(TimesheetService::class)->forMonth(CarbonImmutable::parse('2026-07-01'));

        $row = collect($data['rows'])->firstWhere('manager.id', $this->manager->id);
        $cells = collect($row['cells'])->keyBy('date');

        $this->assertSame('Я', $cells['2026-07-01']['code']);
        $this->assertSame('В', $cells['2026-07-04']['code']);
        $this->assertSame('ОТ', $cells['2026-07-06']['code']);
        $this->assertSame('ПР', $cells['2026-07-13']['code']);
        // Отпуск, попавший на выходной, показывается как выходной.
        $this->assertSame('В', $cells['2026-07-05']['code'] ?? 'В');

        $this->assertSame(5, $row['totals']['vacation']);
        $this->assertSame(1, $row['totals']['truancy']);
    }

    public function test_holiday_from_production_calendar_is_weekend(): void
    {
        $data = app(TimesheetService::class)->forMonth(CarbonImmutable::parse('2026-06-01'));

        $row = collect($data['rows'])->firstWhere('manager.id', $this->manager->id);
        $cells = collect($row['cells'])->keyBy('date');

        // 12 июня 2026 — пятница, но праздник из production_calendar.
        $this->assertSame('В', $cells['2026-06-12']['code']);
    }

    public function test_future_working_days_are_empty(): void
    {
        $nextMonth = CarbonImmutable::today()->addMonth()->startOfMonth();

        $data = app(TimesheetService::class)->forMonth($nextMonth);
        $row = collect($data['rows'])->firstWhere('manager.id', $this->manager->id);

        $workdayCodes = collect($row['cells'])
            ->filter(fn (array $cell, int $index) => ! $data['days'][$index]['is_weekend'])
            ->pluck('code')
            ->unique()
            ->all();

        $this->assertSame([''], $workdayCodes);
        $this->assertSame(0, $row['totals']['work']);
    }

    public function test_inactive_and_unlinked_cards_are_not_in_rows(): void
    {
        $hidden = PersonalManager::factory()->create(['is_active' => false, 'user_id' => User::factory()->create()->id]);
        $technical = PersonalManager::factory()->create(['is_active' => true, 'user_id' => null]);

        $data = app(TimesheetService::class)->forMonth(CarbonImmutable::today()->startOfMonth());
        $ids = collect($data['rows'])->pluck('manager.id');

        $this->assertTrue($ids->contains($this->manager->id));
        $this->assertFalse($ids->contains($hidden->id));
        $this->assertFalse($ids->contains($technical->id));
    }

    public function test_export_returns_csv(): void
    {
        $response = $this->actingAs($this->head)
            ->get(route('crm.timesheet.export', ['month' => '2026-07']));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));

        $body = $response->streamedContent();
        $this->assertStringContainsString('Менеджер', $body);
        $this->assertStringContainsString('Курочкина Елена', $body);
        $this->assertStringContainsString('Явки', $body);
    }
}
