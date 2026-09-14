<?php

namespace Tests\Feature\Crm;

use App\Enums\Crm\PlanTarget;
use App\Models\CrmSalesPlan;
use App\Models\Motivation\MotivationPlanOrder;
use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * «Планы продаж» после отказа от планов на партнёра (14.09.2026): с экрана
 * ставится только план отдела, планы менеджеров читаются из приказов на квартал.
 */
class SalesPlansTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    private PersonalManager $managerProfile;

    private User $client;

    private User $head;

    private string $month;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $this->managerProfile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create(['personal_manager_id' => $this->managerProfile->id]);

        $this->head = User::factory()->create();
        $this->head->assignRole('sales-head');

        $this->month = Carbon::now()->format('Y-m');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function payload(array $rows, ?string $month = null): array
    {
        return ['month' => $month ?? $this->month, 'rows' => $rows];
    }

    #[Test]
    #[TestDox('Руководитель ставит план отдела; повтор за тот же месяц обновляет, а не дублирует')]
    public function head_sets_department_plan(): void
    {
        $this->actingAs($this->head)
            ->postJson(route('crm.plans.store'), $this->payload([['target_type' => 'department', 'amount' => 5_000_000]]))
            ->assertOk()
            ->assertJsonPath('saved', 1);

        $this->actingAs($this->head)
            ->postJson(route('crm.plans.store'), $this->payload([['target_type' => 'department', 'amount' => 5_500_000]]))
            ->assertOk();

        $plan = CrmSalesPlan::query()->department()->sole();
        $this->assertSame(5_500_000.0, $plan->amountValue());
        $this->assertSame($this->head->id, $plan->author_id);
        // У отдела цели нет, но колонка не nullable: MySQL считает NULL-ы
        // в unique-индексе различными, и план отдела продублировался бы.
        $this->assertSame(0, $plan->target_id);
        $this->assertSame(1, $plan->period_month->day);
    }

    #[Test]
    #[TestDox('Пустая сумма снимает план отдела')]
    public function empty_amount_removes_the_department_plan(): void
    {
        CrmSalesPlan::factory()->forMonth($this->month)->create(['amount' => 90_000]);

        $this->actingAs($this->head)
            ->postJson(route('crm.plans.store'), $this->payload([['target_type' => 'department', 'amount' => null]]))
            ->assertOk()
            ->assertJsonPath('removed', 1);

        $this->assertSame(0, CrmSalesPlan::query()->count());
    }

    #[Test]
    #[TestDox('Планы менеджера и партнёра с экрана не принимаются — только приказ на квартал')]
    public function manager_and_client_targets_are_rejected(): void
    {
        $this->actingAs($this->head)
            ->postJson(route('crm.plans.store'), $this->payload([
                ['target_type' => 'manager', 'target_id' => $this->managerProfile->id, 'amount' => 800_000],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rows.0.target_type' => 'С этого экрана ставится только план отдела']);

        $this->actingAs($this->head)
            ->postJson(route('crm.plans.store'), $this->payload([
                ['target_type' => 'client', 'target_id' => $this->client->id, 'amount' => 100_000],
            ]))
            ->assertStatus(422);

        $this->assertSame(0, CrmSalesPlan::query()->count());
    }

    #[Test]
    #[TestDox('Менеджер план отдела не ставит')]
    public function manager_cannot_set_department_plan(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('crm.plans.store'), $this->payload([['target_type' => 'department', 'amount' => 9_000_000]]))
            ->assertOk()
            ->assertJsonPath('saved', 0)
            ->assertJsonPath('skipped', 1);

        $this->assertSame(0, CrmSalesPlan::query()->count());
    }

    #[Test]
    #[TestDox('Страница: план отдела, планы менеджеров с приказом, менеджер видит только себя')]
    public function page_shows_department_and_manager_plans_with_orders(): void
    {
        CrmSalesPlan::factory()->forMonth($this->month)->create(['amount' => 333]);
        CrmSalesPlan::factory()->forManager($this->managerProfile)->forMonth($this->month)->create(['amount' => 111]);
        $colleague = PersonalManager::factory()->create();
        CrmSalesPlan::factory()->forManager($colleague)->forMonth($this->month)->create(['amount' => 222]);

        $order = MotivationPlanOrder::factory()->create([
            'quarter_start' => Carbon::now()->startOfQuarter()->toDateString(),
            'personal_manager_id' => $this->managerProfile->id,
            'status' => MotivationPlanOrder::STATUS_APPROVED,
            'version' => 2,
        ]);

        $asManager = $this->actingAs($this->manager)
            ->getJson(route('crm.plans.data', ['month' => $this->month]))
            ->assertOk();

        $this->assertSame(333.0, (float) $asManager->json('department.amount'));
        $asManager->assertJsonPath('department.can_edit', false);
        $this->assertCount(1, $asManager->json('managers'));
        $asManager->assertJsonPath('managers.0.id', $this->managerProfile->id)
            ->assertJsonPath('managers.0.order.id', $order->id)
            ->assertJsonPath('managers.0.order.version', 2);

        $asHead = $this->actingAs($this->head)
            ->getJson(route('crm.plans.data', ['month' => $this->month]))
            ->assertOk();

        $asHead->assertJsonPath('department.can_edit', true);
        $this->assertCount(2, $asHead->json('managers'));
        $this->assertSame(333.0, (float) $asHead->json('managersSum'));
        $this->assertNull(collect($asHead->json('managers'))->firstWhere('id', $colleague->id)['order']);
        $this->assertArrayNotHasKey('clients', $asHead->json(), 'Партнёров на «Планах продаж» больше нет');
    }

    #[Test]
    #[TestDox('Раздел закрыт без права; ошибки валидации на русском; месяц нормализуется')]
    public function permissions_validation_and_month(): void
    {
        $outsider = User::factory()->create();
        $outsider->givePermissionTo('crm-clients.view');
        $this->actingAs($outsider)->get(route('crm.plans.index'))->assertForbidden();

        $this->actingAs($this->head)
            ->postJson(route('crm.plans.store'), $this->payload([['target_type' => 'department', 'amount' => -5]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rows.0.amount' => 'Сумма плана не может быть отрицательной.']);

        $this->actingAs($this->head)
            ->postJson(route('crm.plans.store'), $this->payload([['target_type' => 'department', 'amount' => 10_000]], '2026-12'))
            ->assertOk();

        $this->assertSame('2026-12-01', CrmSalesPlan::query()->sole()->period_month->format('Y-m-d'));
        $this->assertSame(PlanTarget::DEPARTMENT, CrmSalesPlan::query()->sole()->target_type);
    }
}
