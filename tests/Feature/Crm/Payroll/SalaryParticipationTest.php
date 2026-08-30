<?php

namespace Tests\Feature\Crm\Payroll;

use App\Models\PayrollCalculation;
use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Участие в расчёте зарплаты — свойство менеджера, а не побочный эффект is_active.
 *
 * За карточкой может числиться база отдела (клиенты, планы, отгрузки), а зарплату
 * по схеме ОП человек не получает: владелец, руководитель, «общая» карточка.
 */
class SalaryParticipationTest extends TestCase
{
    use RefreshDatabase;

    private User $head;

    private User $owner;

    private PersonalManager $ownerProfile;

    private PersonalManager $seller;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->head = User::factory()->create();
        $this->head->assignRole('sales-head');

        $this->owner = User::factory()->create();
        $this->owner->assignRole('sales-manager');
        $this->ownerProfile = PersonalManager::factory()->create(['user_id' => $this->owner->id, 'name' => 'Владелец']);

        $sellerUser = User::factory()->create();
        $sellerUser->assignRole('sales-manager');
        $this->seller = PersonalManager::factory()->create(['user_id' => $sellerUser->id, 'name' => 'Продавец']);
    }

    #[Test]
    #[TestDox('Умолчание — участвует: миграция не меняет поведение')]
    public function participation_is_on_by_default(): void
    {
        $this->assertTrue($this->ownerProfile->fresh()->payroll_enabled);

        $this->actingAs($this->head)
            ->getJson('/crm/salary/team/data')
            ->assertOk()
            ->assertJsonCount(2, 'rows');
    }

    #[Test]
    #[TestDox('Исключённый менеджер пропадает из сводки и команд, но карточка остаётся рабочей')]
    public function excluded_manager_leaves_team_summary(): void
    {
        $this->actingAs($this->head)
            ->postJson('/crm/salary/settings/participation', ['manager_id' => $this->ownerProfile->id, 'enabled' => false])
            ->assertOk()
            ->assertJsonPath('saved', true)
            ->assertJsonPath('manager.payroll_enabled', false);

        $this->assertFalse($this->ownerProfile->fresh()->payroll_enabled);
        // Карточка в остальной CRM не изменилась.
        $this->assertTrue($this->ownerProfile->fresh()->is_active);

        $this->actingAs($this->head)
            ->getJson('/crm/salary/team/data')
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.manager.name', 'Продавец');

        // Черновик исключённому не заводится ни сводкой, ни командой.
        $this->artisan('payroll:recalculate')->assertSuccessful();
        $this->assertSame(0, PayrollCalculation::query()->forManager($this->ownerProfile->id)->count());
        $this->assertSame(1, PayrollCalculation::query()->forManager($this->seller->id)->count());
    }

    #[Test]
    #[TestDox('Своя страница исключённого показывает объяснение вместо нулей')]
    public function excluded_manager_sees_explanation(): void
    {
        $this->ownerProfile->forceFill(['payroll_enabled' => false])->save();

        $this->actingAs($this->owner)
            ->get('/crm/salary')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Salary/Index')
                ->where('manager.name', 'Владелец')
                ->where('participates', false)
                ->where('calculation', null)
                ->where('timeline', null));

        $this->assertSame(0, PayrollCalculation::query()->count());
    }

    #[Test]
    #[TestDox('В настройках исключённый виден с выключенным тумблером и возвращается обратно')]
    public function settings_show_and_restore_participation(): void
    {
        $this->ownerProfile->forceFill(['payroll_enabled' => false])->save();

        $this->actingAs($this->head)
            ->getJson('/crm/salary/settings/data')
            ->assertOk()
            ->assertJsonCount(2, 'managers')
            ->assertJsonPath('managers.0.payroll_enabled', false)
            ->assertJsonPath('managers.0.name', 'Владелец');

        $this->actingAs($this->head)
            ->postJson('/crm/salary/settings/participation', ['manager_id' => $this->ownerProfile->id, 'enabled' => true])
            ->assertOk()
            ->assertJsonPath('manager.payroll_enabled', true);

        $this->actingAs($this->head)
            ->getJson('/crm/salary/team/data')
            ->assertOk()
            ->assertJsonCount(2, 'rows');
    }

    #[Test]
    #[TestDox('Переключать участие может только РОП')]
    public function toggle_requires_edit_permission(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/crm/salary/settings/participation', ['manager_id' => $this->ownerProfile->id, 'enabled' => false])
            ->assertForbidden();

        $this->actingAs($this->head)
            ->postJson('/crm/salary/settings/participation', ['manager_id' => $this->ownerProfile->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['enabled']);
    }

    #[Test]
    #[TestDox('Закрытие месяца не трогает исключённых')]
    public function close_month_skips_excluded(): void
    {
        $this->ownerProfile->forceFill(['payroll_enabled' => false])->save();

        $this->artisan('payroll:close-month')->assertSuccessful();

        $previous = Carbon::now()->subMonthNoOverflow()->startOfMonth();
        $this->assertSame(0, PayrollCalculation::query()->forManager($this->ownerProfile->id)->forPeriod($previous)->count());
        $this->assertSame(1, PayrollCalculation::query()->forManager($this->seller->id)->forPeriod($previous)->count());
    }
}
