<?php

namespace Tests\Feature\Debt;

use App\Enums\DebtLevel;
use App\Models\Company;
use App\Models\DebtPause;
use App\Models\DebtState;
use App\Models\PersonalManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Раздел «Дебиторка» в CRM: список по скоупу, разблокировка с потолком по роли,
 * бейдж в карточке партнёра.
 */
class DebtCrmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['crm-finance.view', 'crm-department.view', 'crm-clients-all.view', 'crm-clients.view'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config(['debt.enabled' => true, 'debt.mode' => 'live', 'debt.live_actions' => 'gate']);
    }

    #[Test]
    public function manager_sees_only_own_partners_with_a_level(): void
    {
        [$manager, $card] = $this->actor();
        [, $otherCard] = $this->actor();
        $mine = $this->partnerWithLevel($card, DebtLevel::NO_ORDERS);
        $this->partnerWithLevel($otherCard, DebtLevel::HOLD);
        $this->partnerWithLevel($card, DebtLevel::CLEAN);

        $this->actingAs($manager)
            ->get(route('crm.debt.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Crm/Pages/Debt/Index')
                ->has('rows', 1)
                ->where('rows.0.client.id', $mine->id)
                ->where('rows.0.level', 'no_orders')
                ->where('totals.by_level.no_orders', 1)
                ->where('seesAll', false)
                ->where('pauseMaxDays', 14));
    }

    #[Test]
    public function head_sees_department_and_can_filter_by_level(): void
    {
        [$head] = $this->actor(head: true);
        [, $card] = $this->actor();
        $this->partnerWithLevel($card, DebtLevel::NO_ORDERS);
        $this->partnerWithLevel($card, DebtLevel::HOLD);

        $this->actingAs($head)
            ->get(route('crm.debt.index', ['level' => 'hold']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('rows.0.level', 'hold')
                ->where('seesAll', true)
                ->where('pauseMaxDays', 30));
    }

    #[Test]
    public function pause_is_capped_by_role(): void
    {
        [$manager, $card] = $this->actor();
        $partner = $this->partnerWithLevel($card, DebtLevel::NO_ORDERS);

        $this->actingAs($manager)
            ->post(route('crm.debt.pauses.store'), [
                'user_id' => $partner->id,
                'until' => now()->addDays(20)->toDateString(),
                'reason' => 'Обещал оплатить всю сумму',
            ])
            ->assertSessionHasErrors('until');

        $this->actingAs($manager)
            ->post(route('crm.debt.pauses.store'), [
                'user_id' => $partner->id,
                'until' => now()->addDays(10)->toDateString(),
                'reason' => 'Обещал оплатить всю сумму',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, DebtPause::query()->where('user_id', $partner->id)->whereNull('released_at')->count());

        [$head] = $this->actor(head: true);
        $this->actingAs($head)
            ->post(route('crm.debt.pauses.store'), [
                'user_id' => $partner->id,
                'until' => now()->addDays(25)->toDateString(),
                'reason' => 'Договорились с директором о графике',
            ])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function foreign_partner_cannot_be_paused_and_pause_can_be_released(): void
    {
        [$manager, $card] = $this->actor();
        [, $otherCard] = $this->actor();
        $foreign = $this->partnerWithLevel($otherCard, DebtLevel::NO_ORDERS);
        $mine = $this->partnerWithLevel($card, DebtLevel::NO_ORDERS);

        $this->actingAs($manager)
            ->post(route('crm.debt.pauses.store'), [
                'user_id' => $foreign->id,
                'until' => now()->addDays(5)->toDateString(),
                'reason' => 'Чужой партнёр',
            ])
            ->assertNotFound();

        $pause = DebtPause::create([
            'user_id' => $mine->id,
            'until' => now()->addDays(5)->toDateString(),
            'reason' => 'Обещал',
            'created_by' => $manager->id,
        ]);

        $this->actingAs($manager)
            ->delete(route('crm.debt.pauses.release', $pause))
            ->assertRedirect();

        $this->assertSame(DebtPause::RELEASED_MANUAL, $pause->fresh()->released_reason);
    }

    #[Test]
    public function client_card_carries_debt_explain(): void
    {
        [$manager, $card] = $this->actor();
        $partner = $this->partnerWithLevel($card, DebtLevel::NO_PREORDERS);

        $this->actingAs($manager)
            ->get(route('crm.clients.show', $partner))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canSeeDebt', true)
                ->where('debt.partner.level', 'no_preorders')
                ->has('debt.contractors', 1));
    }

    /**
     * @return array{0: User, 1: PersonalManager}
     */
    private function actor(bool $head = false): array
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo(['crm-finance.view', 'crm-clients.view']);

        if ($head) {
            $actor->givePermissionTo(['crm-department.view', 'crm-clients-all.view']);
        }

        $card = PersonalManager::create(['name' => $head ? 'РОП' : 'Менеджер '.$actor->id, 'user_id' => $actor->id]);

        return [$actor->fresh(), $card];
    }

    private function partnerWithLevel(PersonalManager $card, DebtLevel $level): User
    {
        $partner = User::factory()->create(['personal_manager_id' => $card->id]);
        $company = Company::factory()->create(['user_id' => $partner->id]);

        foreach ([null, $company->id] as $companyId) {
            DebtState::create([
                'user_id' => $partner->id,
                'company_id' => $companyId,
                'level' => $level,
                'since' => now()->toDateString(),
                'overdue_amount' => $level === DebtLevel::CLEAN ? 0 : 50000,
                'overdue_total' => $level === DebtLevel::CLEAN ? 0 : 50000,
                'debt_amount' => $companyId === null ? 50000 : 0,
                'age_days' => 40,
                'lines_count' => 1,
                'reason' => 'тест',
                'dry_run' => false,
                'computed_at' => now(),
            ]);
        }

        return $partner;
    }
}
