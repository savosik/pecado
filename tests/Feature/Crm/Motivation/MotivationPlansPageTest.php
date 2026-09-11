<?php

namespace Tests\Feature\Crm\Motivation;

use App\Enums\Crm\PlanTarget;
use App\Enums\UserKind;
use App\Models\CrmSalesPlan;
use App\Models\Motivation\MotivationPlanOrder;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
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
 * Мастер планов на квартал (карточка mot-33): расчёт черновиков, правка с вето,
 * утверждение с записью в планы продаж, режим просмотра утверждённого.
 */
class MotivationPlansPageTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $head;

    private PersonalManager $profile;

    private CarbonImmutable $quarter;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->quarter = CarbonImmutable::now()->addQuarter()->startOfQuarter();
        $this->head = User::factory()->create(['user_kind' => UserKind::STAFF->value]);
        $this->head->assignRole('sales-head');
        $this->profile = PersonalManager::factory()->create(['user_id' => $this->head->id, 'name' => 'Трипуть']);

        // Отгрузки в каждый рабочий день выборки: медиана считается по дням,
        // и партнёр с одной отгрузкой в месяц дал бы честный ноль.
        $partner = User::factory()->create(['personal_manager_id' => $this->profile->id]);
        $calendar = app(\App\Services\Payroll\Support\WorkingCalendar::class);
        for ($day = $this->quarter->subMonths(6); $day->lt($this->quarter); $day = $day->addDay()) {
            if ($calendar->isWorkingDay($day)) {
                $this->ship($partner, 10_000, $day->setTime(12, 0));
            }
        }
        foreach ([0, 1, 2] as $i) {
            CrmSalesPlan::query()->create([
                'period_month' => $this->quarter->addMonths($i)->toDateString(),
                'target_type' => PlanTarget::MANAGER->value,
                'target_id' => $this->profile->id,
                'amount' => 9_000_000,
            ]);
        }
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

    private function q(): string
    {
        return $this->quarter->format('Y-m');
    }

    #[Test]
    #[TestDox('Страница показывает расчёт рядом с действующим планом и разницу методик')]
    public function page_shows_calculation_against_current_plans(): void
    {
        $this->actingAs($this->head)
            ->get('/crm/motivation/plans?quarter='.$this->q())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Motivation/Plans')
                ->where('can_edit', true)
                ->has('managers', 1)
                ->where('managers.0.order', null)
                ->has('managers.0.months', 3)
                ->where('managers.0.months.0.current_plan', 9_000_000)
                ->where('comparison.current_total', 27_000_000)
                ->where('managers.0.previous_quarter_comparable', false));
    }

    #[Test]
    #[TestDox('Расчёт создаёт черновики, правка с вето блокируется, утверждение пишет планы продаж')]
    public function calculate_override_and_approve_flow(): void
    {
        $data = $this->actingAs($this->head)
            ->postJson('/crm/motivation/plans/calculate', ['quarter' => $this->q()])
            ->assertOk()
            ->json();

        $order = $data['managers'][0]['order'];
        $this->assertSame('draft', $order['status']);
        $months = $data['managers'][0]['months'];
        $this->assertGreaterThan(0, $months[0]['calculated']);

        $raised = array_combine(array_column($months, 'month'), array_map(fn (array $m): float => $m['calculated'] * 2, $months));

        $this->actingAs($this->head)
            ->postJson("/crm/motivation/plans/{$order['id']}/override", ['values' => $raised, 'comment' => 'Перевыполнил прошлый квартал вдвое'])
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'Повышение плана по основанию перевыполнения прошлого периода не допускается (п. 5.4). Половина перевыполнения уже учтена в расчёте.']);

        $lowered = array_combine(array_column($months, 'month'), array_map(fn (array $m): float => round($m['calculated'] * 0.9), $months));

        $this->actingAs($this->head)
            ->postJson("/crm/motivation/plans/{$order['id']}/override", ['values' => $lowered, 'comment' => 'Партнёр прекратил деятельность'])
            ->assertOk()
            ->assertJsonPath('managers.0.order.manual', true);

        $this->actingAs($this->head)
            ->postJson("/crm/motivation/plans/{$order['id']}/approve", ['quarter' => $this->q()])
            ->assertOk()
            ->assertJsonPath('managers.0.order.approved', true);

        foreach ($lowered as $month => $value) {
            $plan = CrmSalesPlan::query()->forPeriod(CarbonImmutable::parse((string) $month))->forManager($this->profile->id)->sole();
            $this->assertSame((float) $value, (float) $plan->amount, 'План читается всеми экранами CRM из планов продаж');
        }

        // Повторный расчёт утверждённый квартал не трогает.
        $again = $this->actingAs($this->head)->postJson('/crm/motivation/plans/calculate', ['quarter' => $this->q()])->assertOk()->json();
        $this->assertTrue($again['managers'][0]['order']['approved']);
        $this->assertSame(1, MotivationPlanOrder::query()->count());
    }

    #[Test]
    #[TestDox('Правка без обоснования отклоняется, менеджеру без права мастер закрыт')]
    public function validation_and_permissions(): void
    {
        $this->actingAs($this->head)->postJson('/crm/motivation/plans/calculate', ['quarter' => $this->q()])->assertOk();
        $order = MotivationPlanOrder::query()->sole();

        $this->actingAs($this->head)
            ->postJson("/crm/motivation/plans/{$order->id}/override", ['values' => [1, 2, 3], 'comment' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['comment']);

        $manager = User::factory()->create(['user_kind' => UserKind::STAFF->value]);
        $manager->assignRole('sales-manager');
        $this->actingAs($manager)->get('/crm/motivation/plans')->assertForbidden();
    }
}
