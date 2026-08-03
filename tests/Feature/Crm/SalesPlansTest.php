<?php

namespace Tests\Feature\Crm;

use App\Enums\Crm\PlanTarget;
use App\Models\CrmSalesPlan;
use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SalesPlansTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private PersonalManager $managerProfile;

    private User $client;

    private User $head;

    private PersonalManager $otherManagerProfile;

    private User $foreignClient;

    private string $month;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $this->managerProfile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create(['personal_manager_id' => $this->managerProfile->id]);

        $this->head = User::factory()->create();
        $this->head->assignRole('sales-head');

        $colleague = User::factory()->create();
        $colleague->assignRole('sales-manager');
        $this->otherManagerProfile = PersonalManager::factory()->create(['user_id' => $colleague->id]);
        $this->foreignClient = User::factory()->create(['personal_manager_id' => $this->otherManagerProfile->id]);

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
    public function manager_sets_plan_for_own_client(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('crm.plans.store'), $this->payload([
                ['target_type' => 'client', 'target_id' => $this->client->id, 'amount' => 250000],
            ]))
            ->assertOk()
            ->assertJsonPath('saved', 1);

        $plan = CrmSalesPlan::query()->firstOrFail();

        $this->assertSame(PlanTarget::CLIENT, $plan->target_type);
        $this->assertSame($this->client->id, $plan->target_id);
        $this->assertSame(250000.0, $plan->amountValue());
        $this->assertSame($this->manager->id, $plan->author_id);
        // Период всегда нормализуется к первому числу — иначе unique-индекс
        // пропустил бы второй план на тот же месяц.
        $this->assertSame(1, $plan->period_month->day);
    }

    #[Test]
    public function repeating_the_same_period_updates_the_plan_instead_of_duplicating(): void
    {
        $rows = [['target_type' => 'client', 'target_id' => $this->client->id, 'amount' => 100000]];

        $this->actingAs($this->manager)->postJson(route('crm.plans.store'), $this->payload($rows))->assertOk();

        $rows[0]['amount'] = 300000;
        $this->actingAs($this->manager)->postJson(route('crm.plans.store'), $this->payload($rows))->assertOk();

        $this->assertSame(1, CrmSalesPlan::query()->count());
        $this->assertSame(300000.0, CrmSalesPlan::query()->firstOrFail()->amountValue());
    }

    #[Test]
    public function empty_amount_removes_the_plan(): void
    {
        CrmSalesPlan::factory()->forClient($this->client)->forMonth($this->month)->create(['amount' => 90000]);

        $this->actingAs($this->manager)
            ->postJson(route('crm.plans.store'), $this->payload([
                ['target_type' => 'client', 'target_id' => $this->client->id, 'amount' => null],
            ]))
            ->assertOk()
            ->assertJsonPath('removed', 1);

        $this->assertSame(0, CrmSalesPlan::query()->count());
    }

    #[Test]
    public function manager_cannot_set_plan_for_foreign_client(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('crm.plans.store'), $this->payload([
                ['target_type' => 'client', 'target_id' => $this->foreignClient->id, 'amount' => 500000],
            ]))
            ->assertOk()
            // Строка вне скоупа тихо пропускается: сетка отправляется целиком,
            // и одна чужая ячейка не должна отменять правку остальных.
            ->assertJsonPath('saved', 0)
            ->assertJsonPath('skipped', 1);

        $this->assertSame(0, CrmSalesPlan::query()->count());
    }

    #[Test]
    public function manager_cannot_set_department_or_manager_plan(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('crm.plans.store'), $this->payload([
                ['target_type' => 'department', 'amount' => 9000000],
                ['target_type' => 'manager', 'target_id' => $this->managerProfile->id, 'amount' => 800000],
            ]))
            ->assertOk()
            ->assertJsonPath('saved', 0)
            ->assertJsonPath('skipped', 2);

        $this->assertSame(0, CrmSalesPlan::query()->count());
    }

    #[Test]
    public function head_sets_department_manager_and_foreign_client_plans(): void
    {
        $this->actingAs($this->head)
            ->postJson(route('crm.plans.store'), $this->payload([
                ['target_type' => 'department', 'amount' => 5000000],
                ['target_type' => 'manager', 'target_id' => $this->managerProfile->id, 'amount' => 2000000],
                ['target_type' => 'client', 'target_id' => $this->foreignClient->id, 'amount' => 150000],
            ]))
            ->assertOk()
            ->assertJsonPath('saved', 3);

        $this->assertSame(3, CrmSalesPlan::query()->count());
        // У отдела цели нет, но колонка не nullable: MySQL считает NULL-ы
        // в unique-индексе различными, и план отдела продублировался бы.
        $this->assertSame(0, CrmSalesPlan::query()->department()->firstOrFail()->target_id);
    }

    #[Test]
    public function department_plan_cannot_be_duplicated_for_one_month(): void
    {
        $this->actingAs($this->head)
            ->postJson(route('crm.plans.store'), $this->payload([
                ['target_type' => 'department', 'amount' => 1000000],
            ]))->assertOk();

        $this->actingAs($this->head)
            ->postJson(route('crm.plans.store'), $this->payload([
                ['target_type' => 'department', 'amount' => 1200000],
            ]))->assertOk();

        $this->assertSame(1, CrmSalesPlan::query()->department()->count());
    }

    #[Test]
    public function grid_shows_only_plans_within_scope(): void
    {
        CrmSalesPlan::factory()->forClient($this->client)->forMonth($this->month)->create(['amount' => 111]);
        CrmSalesPlan::factory()->forClient($this->foreignClient)->forMonth($this->month)->create(['amount' => 222]);
        CrmSalesPlan::factory()->forMonth($this->month)->create(['amount' => 333]);

        $response = $this->actingAs($this->manager)
            ->getJson(route('crm.plans.data', ['month' => $this->month]))
            ->assertOk();

        // План отдела — общая цель, менеджер его видит.
        $this->assertSame(333.0, (float) $response->json('department.amount'));
        $response->assertJsonPath('department.can_edit', false);

        $clients = collect($response->json('clients.data'));

        $this->assertTrue($clients->contains('id', $this->client->id));
        $this->assertFalse($clients->contains('id', $this->foreignClient->id));

        // В строках менеджеров — только он сам, чужие цифры выручки не его дело.
        $this->assertCount(1, $response->json('managers'));
        $this->assertSame($this->managerProfile->id, $response->json('managers.0.id'));
    }

    #[Test]
    public function grid_shows_sum_of_client_plans_against_manager_plan(): void
    {
        CrmSalesPlan::factory()->forManager($this->managerProfile)->forMonth($this->month)->create(['amount' => 100000]);
        CrmSalesPlan::factory()->forClient($this->client)->forMonth($this->month)->create(['amount' => 140000]);

        $response = $this->actingAs($this->head)
            ->getJson(route('crm.plans.data', ['month' => $this->month]))
            ->assertOk();

        $row = collect($response->json('managers'))->firstWhere('id', $this->managerProfile->id);

        $this->assertSame(100000.0, (float) $row['amount']);
        $this->assertSame(140000.0, (float) $row['clients_sum']);
    }

    #[Test]
    public function copying_previous_month_fills_empty_cells_and_keeps_existing(): void
    {
        $target = Carbon::now()->startOfMonth();
        $previous = $target->copy()->subMonthNoOverflow();

        $second = User::factory()->create(['personal_manager_id' => $this->managerProfile->id]);

        CrmSalesPlan::factory()->forClient($this->client)->forMonth($previous)->create(['amount' => 100000]);
        CrmSalesPlan::factory()->forClient($second)->forMonth($previous)->create(['amount' => 200000]);
        CrmSalesPlan::factory()->forClient($this->client)->forMonth($target)->create(['amount' => 999000]);

        $this->actingAs($this->manager)
            ->postJson(route('crm.plans.copy-previous'), ['month' => $target->format('Y-m')])
            ->assertOk()
            ->assertJsonPath('copied', 1)
            ->assertJsonPath('skipped', 1);

        // Уже заданное значение осталось: копирование заполняет пустые ячейки,
        // а не переписывает чужую работу.
        $kept = CrmSalesPlan::query()->forPeriod($target)->forClient($this->client->id)->firstOrFail();
        $this->assertSame(999000.0, $kept->amountValue());

        $copied = CrmSalesPlan::query()->forPeriod($target)->forClient($second->id)->firstOrFail();
        $this->assertSame(200000.0, $copied->amountValue());
    }

    #[Test]
    public function copying_with_overwrite_replaces_existing_values(): void
    {
        $target = Carbon::now()->startOfMonth();
        $previous = $target->copy()->subMonthNoOverflow();

        CrmSalesPlan::factory()->forClient($this->client)->forMonth($previous)->create(['amount' => 100000]);
        CrmSalesPlan::factory()->forClient($this->client)->forMonth($target)->create(['amount' => 999000]);

        $this->actingAs($this->manager)
            ->postJson(route('crm.plans.copy-previous'), [
                'month' => $target->format('Y-m'),
                'overwrite' => true,
            ])
            ->assertOk()
            ->assertJsonPath('copied', 1);

        $this->assertSame(
            100000.0,
            CrmSalesPlan::query()->forPeriod($target)->forClient($this->client->id)->firstOrFail()->amountValue(),
        );
    }

    #[Test]
    public function copying_does_not_pull_foreign_plans_into_own_scope(): void
    {
        $target = Carbon::now()->startOfMonth();
        $previous = $target->copy()->subMonthNoOverflow();

        CrmSalesPlan::factory()->forClient($this->foreignClient)->forMonth($previous)->create(['amount' => 700000]);

        $this->actingAs($this->manager)
            ->postJson(route('crm.plans.copy-previous'), ['month' => $target->format('Y-m')])
            ->assertOk()
            ->assertJsonPath('copied', 0);

        $this->assertSame(0, CrmSalesPlan::query()->forPeriod($target)->count());
    }

    #[Test]
    public function foreign_plan_cannot_be_deleted_and_is_not_disclosed(): void
    {
        $plan = CrmSalesPlan::factory()->forClient($this->foreignClient)->forMonth($this->month)->create();

        // 404, а не 403: иначе перебором id можно было бы узнать, что план
        // соседнего менеджера существует.
        $this->actingAs($this->manager)
            ->deleteJson(route('crm.plans.destroy', $plan->id))
            ->assertNotFound();

        $this->assertSame(1, CrmSalesPlan::query()->count());
    }

    #[Test]
    public function own_client_plan_is_deleted(): void
    {
        $plan = CrmSalesPlan::factory()->forClient($this->client)->forMonth($this->month)->create();

        $this->actingAs($this->manager)
            ->deleteJson(route('crm.plans.destroy', $plan->id))
            ->assertOk();

        $this->assertSame(0, CrmSalesPlan::query()->count());
    }

    #[Test]
    public function manager_cannot_delete_department_plan(): void
    {
        $plan = CrmSalesPlan::factory()->forMonth($this->month)->create();

        // План отдела менеджеру виден (общая цель), но снять его он не может.
        $this->actingAs($this->manager)
            ->deleteJson(route('crm.plans.destroy', $plan->id))
            ->assertForbidden();

        $this->assertSame(1, CrmSalesPlan::query()->count());
    }

    #[Test]
    public function section_is_closed_without_permission(): void
    {
        // Сотрудник с доступом в CRM, но без права на планы: без CRM-права вовсе
        // его развернул бы middleware 'crm', и проверка гейта ничего не доказала бы.
        $outsider = User::factory()->create();
        $outsider->givePermissionTo('crm-clients.view');

        $this->actingAs($outsider)->get(route('crm.plans.index'))->assertForbidden();
    }

    #[Test]
    public function validation_errors_are_in_russian(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('crm.plans.store'), $this->payload([
                ['target_type' => 'client', 'target_id' => $this->client->id, 'amount' => -5],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rows.0.amount' => 'Сумма плана не может быть отрицательной.']);

        $this->actingAs($this->manager)
            ->postJson(route('crm.plans.store'), $this->payload([
                ['target_type' => 'client', 'amount' => 1000],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rows.0.target_id' => 'Не указано, кому ставится план.']);
    }

    #[Test]
    public function month_is_taken_from_the_request_and_normalized(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('crm.plans.store'), $this->payload([
                ['target_type' => 'client', 'target_id' => $this->client->id, 'amount' => 10000],
            ], '2026-12'))
            ->assertOk();

        $plan = CrmSalesPlan::query()->firstOrFail();

        $this->assertSame('2026-12-01', $plan->period_month->format('Y-m-d'));
    }
}
