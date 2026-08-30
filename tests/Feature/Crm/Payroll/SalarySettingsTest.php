<?php

namespace Tests\Feature\Crm\Payroll;

use App\Jobs\Payroll\RecalculatePayrollDraft;
use App\Models\PayrollCalculation;
use App\Models\PayrollManualAdjustment;
use App\Models\PayrollParamOverride;
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

class SalarySettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $head;

    private User $manager;

    private PersonalManager $profile;

    private string $month;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->head = User::factory()->create();
        $this->head->assignRole('sales-head');

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $this->profile = PersonalManager::factory()->create(['user_id' => $this->manager->id, 'name' => 'Курочкина']);

        $this->month = Carbon::now()->format('Y-m');
    }

    #[Test]
    #[TestDox('Настройки открывает только РОП; менеджеру — 403')]
    public function settings_require_edit_permission(): void
    {
        $this->actingAs($this->manager)->get('/crm/salary/settings')->assertForbidden();
        $this->actingAs($this->manager)->postJson('/crm/salary/settings/params', [])->assertForbidden();
        $this->actingAs($this->manager)->postJson('/crm/salary/adjustments', [])->assertForbidden();

        $this->actingAs($this->head)
            ->get('/crm/salary/settings')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Salary/Settings')
                ->where('month', $this->month)
                ->where('scheme.version', 1)
                ->where('managers.0.name', 'Курочкина')
                ->where('managers.0.params.salary.amount', 70000)
                ->where('managers.0.sources.salary.amount', 'scheme')
                ->has('components.kpi_bonus.schema')
                ->has('months'));
    }

    #[Test]
    #[TestDox('Сохранение параметров: отклонение записывается, совпадение удаляет строку, пересчёт ставится')]
    public function store_params_keeps_only_deviation(): void
    {
        $this->actingAs($this->head)
            ->postJson('/crm/salary/settings/params', [
                'manager_id' => $this->profile->id,
                'month' => $this->month,
                'component' => 'salary',
                'params' => ['amount' => 80000],
                'comment' => 'испытательный срок закончился',
            ])
            ->assertOk()
            ->assertJsonPath('saved', true)
            ->assertJsonPath('manager.params.salary.amount', 80000)
            ->assertJsonPath('manager.sources.salary.amount', 'month');

        $this->assertSame(1, PayrollParamOverride::query()->count());
        Queue::assertPushed(RecalculatePayrollDraft::class, fn (RecalculatePayrollDraft $job): bool => $job->managerId === $this->profile->id && $job->source === 'params');

        $this->actingAs($this->head)
            ->postJson('/crm/salary/settings/params', [
                'manager_id' => $this->profile->id,
                'month' => $this->month,
                'component' => 'salary',
                'params' => ['amount' => 70000],
            ])
            ->assertOk()
            ->assertJsonPath('manager.sources.salary.amount', 'scheme');

        $this->assertSame(0, PayrollParamOverride::query()->count());
    }

    #[Test]
    #[TestDox('Постоянные параметры сохраняются без месяца и действуют на все месяцы')]
    public function permanent_params(): void
    {
        $this->actingAs($this->head)
            ->postJson('/crm/salary/settings/params', [
                'manager_id' => $this->profile->id,
                'component' => 'salary',
                'params' => ['amount' => 90000],
            ])
            ->assertOk()
            ->assertJsonPath('manager.sources.salary.amount', 'permanent');

        $this->actingAs($this->head)
            ->getJson('/crm/salary/settings/data?month='.Carbon::now()->addMonth()->format('Y-m'))
            ->assertOk()
            ->assertJsonPath('managers.0.params.salary.amount', 90000);
    }

    #[Test]
    #[TestDox('Невалидные параметры отвергаются с русским сообщением')]
    public function invalid_params_are_rejected(): void
    {
        $response = $this->actingAs($this->head)
            ->postJson('/crm/salary/settings/params', [
                'manager_id' => $this->profile->id,
                'month' => $this->month,
                'component' => 'kpi_bonus',
                'params' => [
                    'base' => 85000,
                    'cap' => 2,
                    'discipline_penalty' => ['tiers' => [['from_days' => 3, 'to_days' => null, 'coefficient' => 1.5], ['from_days' => 8, 'to_days' => null, 'coefficient' => 3]]],
                    'active_clients' => ['ladder' => [['from_share' => 0.5, 'multiplier' => 0.8]]],
                ],
            ])
            ->assertStatus(422);

        $errors = implode(' ', $response->json('errors.params'));
        $this->assertStringContainsString('Первая ступень', $errors);
        $this->assertStringContainsString('открыта', $errors);

        $this->actingAs($this->head)
            ->postJson('/crm/salary/settings/params', [
                'manager_id' => $this->profile->id,
                'component' => 'nonexistent',
                'params' => ['x' => 1],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['component']);
    }

    #[Test]
    #[TestDox('Сброс слоя и копирование месяца')]
    public function reset_and_copy(): void
    {
        $this->actingAs($this->head)->postJson('/crm/salary/settings/params', [
            'manager_id' => $this->profile->id, 'month' => $this->month, 'component' => 'salary', 'params' => ['amount' => 80000],
        ])->assertOk();

        $next = Carbon::now()->addMonth()->format('Y-m');

        $this->actingAs($this->head)
            ->postJson('/crm/salary/settings/copy-month', ['from' => $this->month, 'to' => $next])
            ->assertOk()
            ->assertJsonPath('copied', 1);

        $this->actingAs($this->head)
            ->deleteJson('/crm/salary/settings/params', ['manager_id' => $this->profile->id, 'month' => $this->month, 'component' => 'salary'])
            ->assertOk()
            ->assertJsonPath('manager.sources.salary.amount', 'scheme');

        $this->assertSame(1, PayrollParamOverride::query()->count());   // осталась только копия в следующем месяце
    }

    #[Test]
    #[TestDox('Утверждённый месяц не правится: ни параметры, ни строки')]
    public function frozen_month_is_locked(): void
    {
        PayrollCalculation::factory()->forMonth(Carbon::now())->approved()->create(['personal_manager_id' => $this->profile->id]);

        $this->actingAs($this->head)->postJson('/crm/salary/settings/params', [
            'manager_id' => $this->profile->id, 'month' => $this->month, 'component' => 'salary', 'params' => ['amount' => 80000],
        ])->assertStatus(422)->assertJsonPath('message', 'Расчёт за этот месяц утверждён — сначала переоткройте его.');

        $this->actingAs($this->head)->postJson('/crm/salary/adjustments', [
            'manager_id' => $this->profile->id, 'month' => $this->month, 'component' => 'extra_income', 'label' => 'ТГ', 'price' => 5000,
        ])->assertStatus(422);

        // Постоянные параметры — не про месяц, их менять можно.
        $this->actingAs($this->head)->postJson('/crm/salary/settings/params', [
            'manager_id' => $this->profile->id, 'component' => 'salary', 'params' => ['amount' => 80000],
        ])->assertOk();
    }

    #[Test]
    #[TestDox('Ручные строки: добавить, увидеть, удалить; сумма = количество × цена')]
    public function adjustments_crud(): void
    {
        $this->actingAs($this->head)
            ->postJson('/crm/salary/adjustments', [
                'manager_id' => $this->profile->id,
                'month' => $this->month,
                'component' => 'extra_income',
                'label' => 'ТГ-канал',
                'qty' => 2,
                'price' => 2500,
            ])
            ->assertOk()
            ->assertJsonPath('adjustments.0.amount', 5000)
            ->assertJsonPath('adjustments.0.manager_name', 'Курочкина')
            ->assertJsonPath('adjustments.0.author', $this->head->name);

        $this->actingAs($this->head)
            ->postJson('/crm/salary/adjustments', [
                'manager_id' => $this->profile->id, 'month' => $this->month, 'component' => 'manual_correction',
                'label' => 'Удержание', 'price' => -3000, 'comment' => 'порча образцов',
            ])
            ->assertOk()
            ->assertJsonCount(2, 'adjustments');

        Queue::assertPushed(RecalculatePayrollDraft::class);

        $this->actingAs($this->head)
            ->postJson('/crm/salary/adjustments', ['manager_id' => $this->profile->id, 'month' => $this->month, 'component' => 'bonus', 'label' => '', 'price' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['component', 'label', 'price']);

        $id = PayrollManualAdjustment::query()->where('label', 'Удержание')->value('id');

        $this->actingAs($this->head)
            ->deleteJson('/crm/salary/adjustments/'.$id)
            ->assertOk()
            ->assertJsonCount(1, 'adjustments');

        $this->assertSame(1, PayrollManualAdjustment::query()->count());
    }
}
