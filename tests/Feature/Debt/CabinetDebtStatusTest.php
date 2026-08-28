<?php

namespace Tests\Feature\Debt;

use App\Enums\DebtLevel;
use App\Models\Company;
use App\Models\DebtPause;
use App\Models\DebtState;
use App\Models\SettlementEntry;
use App\Models\User;
use App\Services\Debt\CabinetDebtStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Кабинет: норма невидима, ступень видна при значимой просрочке,
 * «срок подходит» — за несколько дней, разблокировка снимает ограничение.
 */
class CabinetDebtStatusTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = CarbonImmutable::parse('2026-08-27');
        CarbonImmutable::setTestNow($this->today);
        config(['debt.enabled' => true, 'debt.mode' => 'live', 'debt.live_actions' => 'cabinet', 'debt.due_soon_days' => 3]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function clean_client_without_due_lines_gets_nothing(): void
    {
        $user = User::factory()->create();

        $this->assertNull($this->cabinetStatus()->forUser($user, $this->today));
    }

    #[Test]
    public function restricting_level_is_visible_with_contractors(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id, 'name' => 'ООО Ромашка']);
        $this->state($user, null, DebtLevel::NO_ORDERS, 126098);
        $this->state($user, $company, DebtLevel::NO_ORDERS, 126098);

        $payload = $this->cabinetStatus()->forUser($user, $this->today);

        $this->assertTrue($payload['visible']);
        $this->assertTrue($payload['restricted']);
        $this->assertTrue($payload['blocks_orders']);
        $this->assertSame('ООО Ромашка', $payload['contractors'][0]['company_name']);
        $this->assertSame('no_orders:2026-08-27', $payload['key']);
    }

    #[Test]
    public function active_pause_lifts_restriction_but_keeps_overdue_visible(): void
    {
        $user = User::factory()->create();
        $this->state($user, null, DebtLevel::NO_ORDERS, 126098);
        DebtPause::create([
            'user_id' => $user->id,
            'until' => $this->today->addDays(5)->toDateString(),
            'reason' => 'Обещал',
            'created_by' => User::factory()->create()->id,
        ]);

        $payload = $this->cabinetStatus()->forUser($user, $this->today);

        $this->assertTrue($payload['visible']);
        $this->assertFalse($payload['restricted']);
        $this->assertSame('01.09.2026', $payload['pause']['until']);
    }

    #[Test]
    public function due_soon_lines_are_listed_even_for_clean_client(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id]);
        SettlementEntry::factory()->create([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'user_id' => $user->id,
            'company_id' => $company->id,
            'document_kind' => 'shipment',
            'document_number' => '29УТ-1',
            'date' => $this->today->addDays(2)->toDateString(),
            'amount' => 15000,
            'settled_amount' => 0,
        ]);

        $payload = $this->cabinetStatus()->forUser($user, $this->today);

        $this->assertFalse($payload['visible']);
        $this->assertSame(1, $payload['due_soon']['count']);
        $this->assertSame(15000.0, $payload['due_soon']['amount']);
        $this->assertSame('29.08.2026', $payload['due_soon']['nearest_date']);
    }

    #[Test]
    public function shadow_mode_and_staff_see_nothing(): void
    {
        $user = User::factory()->create();
        $this->state($user, null, DebtLevel::HOLD, 50000);

        config(['debt.mode' => 'shadow']);
        $this->assertNull($this->cabinetStatus()->forUser($user, $this->today));

        config(['debt.mode' => 'live']);
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $staff = User::factory()->create();
        $staff->assignRole('sales-manager');
        $this->assertNull($this->cabinetStatus()->forUser($staff, $this->today));
    }

    private function state(User $user, ?Company $company, DebtLevel $level, float $overdue): void
    {
        DebtState::create([
            'user_id' => $user->id,
            'company_id' => $company?->id,
            'level' => $level,
            'since' => '2026-08-27',
            'overdue_amount' => $overdue,
            'overdue_total' => $overdue,
            'oldest_due_date' => '2026-07-05',
            'age_days' => 54,
            'lines_count' => 1,
            'reason' => 'тест',
            'dry_run' => false,
            'computed_at' => now(),
        ]);
    }

    private function cabinetStatus(): CabinetDebtStatus
    {
        return app(CabinetDebtStatus::class);
    }
}
