<?php

namespace Tests\Feature\Crm;

use App\Models\CrmTask;
use App\Models\Currency;
use App\Models\Order;
use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Рабочий список в плане продаж (crm-24).
 *
 * Главный инвариант карточки: колонки сетки планов и списка партнёров считает
 * один сервис, поэтому одинаковы для одного партнёра. Расхождение здесь —
 * это цифры, которые на брифинге читаются как ошибка.
 */
class PlansWorkingListTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        Currency::factory()->create(['code' => 'RUB', 'is_base' => true, 'exchange_rate' => 1]);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $card = PersonalManager::factory()->create(['user_id' => $this->manager->id]);

        $this->client = User::factory()->create(['personal_manager_id' => $card->id]);
    }

    #[Test]
    public function plan_grid_and_client_list_agree_on_the_same_partner(): void
    {
        Order::factory()->create([
            'user_id' => $this->client->id,
            'currency_code' => 'RUB',
            'erp_created_at' => now()->subDays(4),
            'total_amount' => 123_456,
        ]);

        CrmTask::factory()->create([
            'author_id' => $this->manager->id,
            'assignee_id' => $this->manager->id,
            'client_user_id' => $this->client->id,
            'title' => 'Позвонить по остаткам',
            'due_at' => now()->addDay(),
        ]);

        $fromList = $this->actingAs($this->manager)
            ->get(route('crm.clients.index'))
            ->viewData('page')['props']['clients']['data'][0];

        $fromPlans = $this->actingAs($this->manager)
            ->get(route('crm.plans.index'))
            ->viewData('page')['props']['clients']['data'][0];

        $this->assertSame($fromList['last_order'], $fromPlans['last_order']);
        $this->assertSame($fromList['tasks']['next'], $fromPlans['next_task']);
        $this->assertSame($fromList['last_visit'], $fromPlans['last_visit']);
    }

    #[Test]
    public function the_grid_carries_enough_to_run_a_briefing(): void
    {
        Order::factory()->create([
            'user_id' => $this->client->id,
            'currency_code' => 'RUB',
            'erp_created_at' => now()->subDays(2),
            'total_amount' => 90_000,
        ]);

        $this->actingAs($this->manager)
            ->get(route('crm.plans.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('clients.data.0.last_order.amount_rub', 90000)
                ->has('clients.data.0.last_visit')
                ->has('clients.data.0.next_task'));
    }

    /**
     * Брифинговый список — это вкладка «Выполнение», отсортированная
     * по отставанию, а не алфавитная сетка ввода планов. Именно на неё
     * смотрят вслух, и именно ей нужны визит, заказ и задача.
     */
    #[Test]
    public function progress_list_carries_the_same_briefing_columns(): void
    {
        \App\Models\CrmSalesPlan::create([
            'target_type' => 'client',
            'target_id' => $this->client->id,
            'period_month' => now()->startOfMonth()->toDateString(),
            'amount' => 100_000,
        ]);

        Order::factory()->create([
            'user_id' => $this->client->id,
            'currency_code' => 'RUB',
            'erp_created_at' => now()->subDays(3),
            'total_amount' => 55_000,
        ]);

        CrmTask::factory()->create([
            'author_id' => $this->manager->id,
            'assignee_id' => $this->manager->id,
            'client_user_id' => $this->client->id,
            'title' => 'Позвонить до пятницы',
            'due_at' => now()->addDays(2),
        ]);

        $rows = $this->actingAs($this->manager)
            ->getJson(route('crm.plans.progress', ['month' => now()->format('Y-m')]))
            ->assertOk()
            ->json('clients');

        $row = collect($rows)->firstWhere('id', $this->client->id);

        $this->assertNotNull($row, 'Партнёр с планом обязан быть в списке выполнения');
        $this->assertEqualsWithDelta(55000, $row['last_order']['amount_rub'], 0.01);
        $this->assertSame('Позвонить до пятницы', $row['next_task']['title']);
        $this->assertArrayHasKey('last_visit', $row);
    }

    /**
     * Сетка планов подчиняется тому же разрезу, что список партнёров: раздел
     * открывается сфокусированным на своих.
     */
    #[Test]
    public function the_grid_honours_the_scope(): void
    {
        $foreignCard = PersonalManager::factory()->create();
        User::factory()->create(['personal_manager_id' => $foreignCard->id]);

        $this->actingAs($this->manager)
            ->get(route('crm.plans.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('clients.total', 1));

        $this->actingAs($this->manager)
            ->get(route('crm.plans.index', ['scope' => 'department']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('clients.total', 2));
    }
}
