<?php

namespace Tests\Feature\Crm\Payroll;

use App\Models\CrmSalesPlan;
use App\Models\PayrollInvoiceSettlement;
use App\Models\PayrollManualAdjustment;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\Analytics\AnalyticsContext;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\ShipmentAnalyticsService;
use App\Services\Crm\PlanProgressService;
use App\Services\Crm\PlanScope;
use App\Services\Payroll\PayrollInputCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class PayrollInputCollectorTest extends TestCase
{
    use RefreshDatabase;

    private PersonalManager $manager;

    private User $clientA;

    private User $clientB;

    private User $clientC;

    private User $foreign;

    private Carbon $month;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();   // наблюдатели отгрузок ставят пересчёт — здесь он не нужен

        $this->manager = PersonalManager::factory()->create(['user_id' => User::factory()->create()->id]);
        $this->clientA = User::factory()->create(['personal_manager_id' => $this->manager->id, 'name' => 'Альфа']);
        $this->clientB = User::factory()->create(['personal_manager_id' => $this->manager->id, 'name' => 'Бета']);
        $this->clientC = User::factory()->create(['personal_manager_id' => $this->manager->id, 'name' => 'Гамма']);
        $this->foreign = User::factory()->create(['personal_manager_id' => PersonalManager::factory()->create()->id]);
        $this->month = Carbon::now()->startOfMonth();
    }

    private function shipment(User $client, float $total, ?Carbon $date = null): Shipment
    {
        $date ??= $this->month->copy()->addDays(2);

        $shipment = Shipment::create([
            'uuid' => (string) Str::uuid(),
            'erp_number' => '29УТ-'.random_int(100000, 999999),
            'user_id' => $client->id,
            'date' => $date->toDateString(),
            'erp_created_at' => $date,
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => $total,
        ]);

        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => 1,
            'price' => $total,
            'total' => $total,
            'subtotal' => $total,
        ]);

        return $shipment;
    }

    #[Test]
    #[TestDox('Входы собираются только по партнёрам менеджера и совпадают с движком планов')]
    public function collects_inputs_for_manager_scope(): void
    {
        CrmSalesPlan::factory()->forMonth($this->month)->forManager($this->manager)->create(['amount' => 1_000_000]);
        CrmSalesPlan::factory()->forMonth($this->month)->forClient($this->clientA)->create(['amount' => 300_000]);
        CrmSalesPlan::factory()->forMonth($this->month)->forClient($this->clientB)->create(['amount' => 200_000]);

        $shipmentA = $this->shipment($this->clientA, 300_000);
        $this->shipment($this->clientC, 100_000);      // без плана — в выручку входит, в плановые нет
        $this->shipment($this->foreign, 500_000);      // чужой партнёр — не входит никуда

        PayrollInvoiceSettlement::factory()
            ->settledLate($this->month->copy()->addDays(4)->toDateString(), $this->month->copy()->addDays(11)->toDateString(), 5, 7)
            ->create(['shipment_id' => $shipmentA->id, 'user_id' => $this->clientA->id, 'total_amount' => 12_000]);

        PayrollInvoiceSettlement::factory()->create([
            'shipment_id' => Shipment::factory()->create(['user_id' => $this->clientB->id])->id,
            'user_id' => $this->clientB->id,
            'total_amount' => 5_000,
            'due_on' => $this->month->copy()->addDays(20)->toDateString(),
            'payment_status' => Shipment::PAYMENT_UNPAID,
        ]);

        PayrollInvoiceSettlement::factory()
            ->settledLate($this->month->copy()->addDays(4)->toDateString(), $this->month->copy()->addDays(11)->toDateString(), 9, 12)
            ->create(['shipment_id' => Shipment::factory()->create(['user_id' => $this->foreign->id])->id, 'user_id' => $this->foreign->id]);

        PayrollManualAdjustment::factory()->forMonth($this->month)->create(['personal_manager_id' => $this->manager->id]);
        PayrollManualAdjustment::factory()->forMonth($this->month)->correction(-2000, 'Удержание')->create(['personal_manager_id' => $this->manager->id]);

        $inputs = app(PayrollInputCollector::class)->collect($this->manager->id, $this->month);

        $this->assertSame(1_000_000.0, $inputs->plan);
        $this->assertSame(400_000.0, $inputs->revenue);

        $progress = app(PlanProgressService::class)->progress(
            $this->month,
            PlanScope::manager($this->manager->id, [$this->clientA->id, $this->clientB->id, $this->clientC->id], 'm'),
        );
        $this->assertSame($progress['fact'], $inputs->revenue);

        $this->assertCount(2, $inputs->plannedClients);
        $this->assertCount(1, $inputs->activeClients());
        $this->assertSame('Альфа', $inputs->activeClients()[0]->name);
        $this->assertSame(300_000.0, $inputs->activeClients()[0]->fact);
        $this->assertSame(1, $inputs->unplannedActiveCount);   // Гамма купила без плана

        $this->assertCount(1, $inputs->invoices);
        $this->assertSame(5, $inputs->invoices[0]->delayWorkingDays);
        $this->assertSame(12_000.0, $inputs->invoices[0]->amount);
        $this->assertSame('Альфа', $inputs->invoices[0]->partnerName);

        $this->assertCount(1, $inputs->atRiskInvoices);
        $this->assertSame('Бета', $inputs->atRiskInvoices[0]->partnerName);

        $this->assertCount(1, $inputs->extraItems);
        $this->assertSame(5000.0, $inputs->extraItems[0]->amount);
        $this->assertCount(1, $inputs->corrections);
        $this->assertSame(-2000.0, $inputs->corrections[0]->amount);

        $this->assertGreaterThan(0, $inputs->workingDays['total']);
        $this->assertSame($this->month->toDateString(), $inputs->month);
    }

    #[Test]
    #[TestDox('Менеджер без партнёров и планов даёт пустые входы, а не ошибку')]
    public function empty_manager_gives_empty_inputs(): void
    {
        $lonely = PersonalManager::factory()->create();

        $inputs = app(PayrollInputCollector::class)->collect($lonely->id, $this->month);

        $this->assertNull($inputs->plan);
        $this->assertSame(0.0, $inputs->revenue);
        $this->assertSame([], $inputs->plannedClients);
        $this->assertSame([], $inputs->invoices);
        $this->assertSame(0, $inputs->unplannedActiveCount);
    }

    #[Test]
    #[TestDox('Купившие без плана считаются отдельно и вместе с активными дают «покупали в месяце» из /crm/plans')]
    public function unplanned_buyers_explain_the_gap_with_plans_screen(): void
    {
        CrmSalesPlan::factory()->forMonth($this->month)->forClient($this->clientA)->create(['amount' => 300_000]);
        CrmSalesPlan::factory()->forMonth($this->month)->forClient($this->clientB)->create(['amount' => 200_000]);

        $this->shipment($this->clientA, 300_000);   // план есть и купил — активный плановый
        $this->shipment($this->clientC, 100_000);   // купил, но плана на месяц ему не поставили
        $this->shipment($this->foreign, 500_000);   // чужой партнёр — ни в ту, ни в другую цифру
        // Бета: план есть, отгрузок нет — в плановых числится, в активных нет.

        $inputs = app(PayrollInputCollector::class)->collect($this->manager->id, $this->month);

        $this->assertCount(2, $inputs->plannedClients);
        $this->assertCount(1, $inputs->activeClients());
        $this->assertSame(1, $inputs->unplannedActiveCount);

        // Разрез по менеджерам на /crm/plans считает всех партнёров с отгрузкой —
        // именно из-за этого его цифра больше, и разница обязана быть ровно
        // числом внеплановых покупателей, иначе объяснение на экране врёт.
        $row = app(ShipmentAnalyticsService::class)->byManager(
            AnalyticsContext::forScope(
                [$this->clientA->id, $this->clientB->id, $this->clientC->id, $this->foreign->id],
                AnalyticsContext::DATE_ERP,
                null,
            ),
            new AnalyticsFilters(
                dateFrom: $this->month->copy()->startOfDay()->toImmutable(),
                dateTo: $this->month->copy()->endOfMonth()->endOfDay()->toImmutable(),
            ),
        )->firstWhere('manager_id', $this->manager->id);

        $this->assertSame(2, $row['clients_count']);
        $this->assertSame($row['clients_count'], count($inputs->activeClients()) + $inputs->unplannedActiveCount);
    }
}
