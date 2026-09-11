<?php

namespace Tests\Feature\Crm\Motivation;

use App\Enums\UserKind;
use App\Models\Motivation\MotivationObjection;
use App\Models\PayrollCalculation;
use App\Models\PayrollManualAdjustment;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\Motivation\MotivationSchemeInstaller;
use App\Services\Payroll\PayrollCalculationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Сводка отдела и ведомость к утверждению (карточка mot-34).
 */
class MotivationTeamTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $head;

    private PersonalManager $first;

    private PersonalManager $second;

    private CarbonImmutable $month;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->month = CarbonImmutable::now()->startOfMonth();
        $this->head = User::factory()->create(['user_kind' => UserKind::STAFF->value]);
        $this->head->assignRole('sales-head');
        $this->first = PersonalManager::factory()->create(['user_id' => $this->head->id, 'name' => 'Первый']);
        $this->second = PersonalManager::factory()->create(['name' => 'Второй']);

        app(MotivationSchemeInstaller::class)->install($this->month);

        $partner = User::factory()->create(['personal_manager_id' => $this->first->id]);
        $this->ship($partner, 300_000, $this->month->addDays(2));
    }

    private function ship(User $partner, float $amount, CarbonImmutable $date): void
    {
        $shipment = Shipment::create([
            'uuid' => (string) Str::uuid(),
            'erp_number' => '29УТ-'.random_int(100000, 999999),
            'user_id' => $partner->id,
            'date' => $date->toDateString(),
            'erp_created_at' => $date,
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => $amount,
        ]);
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => 1,
            'price' => $amount,
            'total' => $amount,
            'subtotal' => $amount,
        ]);
    }

    #[Test]
    #[TestDox('Сводка: строка на работника, итоги отдела, готовность к закрытию')]
    public function summary_lists_workers_and_department_totals(): void
    {
        $this->actingAs($this->head)
            ->get('/crm/motivation/team')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Motivation/Team')
                ->has('rows', 2)
                ->where('rows.0.manager.name', 'Второй')
                ->where('rows.1.shipped', 300_000)
                ->where('department.shipped', 300_000)
                ->where('department.payroll', 150_000)
                ->has('readiness', 4));

        $this->actingAs($this->head)
            ->get('/crm/motivation/approval')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Motivation/Approval')
                ->has('objections')
                ->has('corrections')
                ->where('adjustment_limit', 0.15));
    }

    #[Test]
    #[TestDox('Жизненный цикл: утвердить → выплатить → переоткрыть с основанием')]
    public function approval_lifecycle(): void
    {
        $calc = app(PayrollCalculationService::class)->ensureDraft($this->first->id, $this->month);

        $this->actingAs($this->head)->postJson("/crm/motivation/calculations/{$calc->id}/approve")->assertOk()
            ->assertJsonPath('rows.1.calculation.status', 'approved');

        $this->actingAs($this->head)->postJson("/crm/motivation/calculations/{$calc->id}/recalculate")->assertUnprocessable();

        $this->actingAs($this->head)->postJson("/crm/motivation/calculations/{$calc->id}/paid")->assertOk()
            ->assertJsonPath('rows.1.calculation.status', 'paid');

        $this->actingAs($this->head)->postJson("/crm/motivation/calculations/{$calc->id}/reopen", ['comment' => ''])->assertUnprocessable();

        $this->actingAs($this->head)->postJson("/crm/motivation/calculations/{$calc->id}/reopen", ['comment' => 'Ошибка в данных 1С'])->assertOk()
            ->assertJsonPath('rows.1.calculation.status', 'draft')
            ->assertJsonPath('rows.1.calculation.version', 2);

        $this->assertSame(2, PayrollCalculation::query()->forManager($this->first->id)->count(), 'Прежняя версия осталась в истории');
    }

    #[Test]
    #[TestDox('Корректировка сверх предела блокируется; в пределе — вносится и попадает в расчёт')]
    public function correction_limit_is_enforced(): void
    {
        // Переменная часть у второго работника — ноль (отгрузок нет): предел ноль, любая корректировка блокируется.
        $this->actingAs($this->head)
            ->postJson('/crm/motivation/adjustments', ['manager_id' => $this->second->id, 'month' => $this->month->format('Y-m'), 'amount' => 1000, 'reason' => 'Проверка предела'])
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'Предел разовой корректировки — 15 % от переменной части (0 ₽). С учётом уже внесённых корректировок допустимо ещё 0 ₽.']);

        $this->actingAs($this->head)
            ->postJson('/crm/motivation/adjustments', ['manager_id' => $this->second->id, 'month' => $this->month->format('Y-m'), 'amount' => 0, 'reason' => 'Ноль'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);

        $this->assertSame(0, PayrollManualAdjustment::query()->count());
    }

    #[Test]
    #[TestDox('Возражение: отказ требует обоснования, принятие переоткрывает месяц новой версией')]
    public function objection_responses(): void
    {
        $calculations = app(PayrollCalculationService::class);
        $calc = $calculations->approve($calculations->ensureDraft($this->first->id, $this->month), $this->head);

        $objection = MotivationObjection::factory()->create([
            'calculation_id' => $calc->id,
            'personal_manager_id' => $this->first->id,
            'author_id' => $this->head->id,
        ]);

        $this->actingAs($this->head)
            ->postJson("/crm/motivation/objections/{$objection->id}/respond", ['decision' => 'rejected', 'response' => ''])
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'Отказ требует обоснования (п. 11.3).']);

        $this->actingAs($this->head)
            ->postJson("/crm/motivation/objections/{$objection->id}/respond", ['decision' => 'accepted', 'response' => 'Отгрузка найдена, пересчитаем'])
            ->assertOk()
            ->assertJsonPath('objections.0.status', 'accepted')
            ->assertJsonPath('rows.1.calculation.status', 'draft')
            ->assertJsonPath('rows.1.calculation.version', 2);

        $this->actingAs($this->head)
            ->postJson("/crm/motivation/objections/{$objection->id}/respond", ['decision' => 'rejected', 'response' => 'Повторно'])
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'На это возражение уже дан ответ.']);
    }

    #[Test]
    #[TestDox('XLSX выгружается; менеджеру без права сводка закрыта')]
    public function export_and_permissions(): void
    {
        $response = $this->actingAs($this->head)->get('/crm/motivation/team/export?month='.$this->month->format('Y-m'));
        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('content-type'));

        $manager = User::factory()->create(['user_kind' => UserKind::STAFF->value]);
        $manager->assignRole('sales-manager');
        $this->actingAs($manager)->get('/crm/motivation/team')->assertForbidden();
        $this->actingAs($manager)->get('/crm/motivation/approval')->assertForbidden();
    }
}
