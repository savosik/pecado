<?php

namespace Tests\Feature\Crm\Payroll;

use App\Events\PartnerSettlementsChanged;
use App\Events\Payroll\PayrollInputsChanged;
use App\Jobs\Payroll\ProjectInvoiceSettlements;
use App\Models\Company;
use App\Models\PayrollInvoiceSettlement;
use App\Models\PersonalManager;
use App\Models\SettlementEntry;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Payroll\Invoices\PayrollInvoiceSettlementProjector;
use App\Services\Payroll\Support\WorkingCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class PayrollInvoiceSettlementProjectorTest extends TestCase
{
    use RefreshDatabase;

    private PersonalManager $manager;

    private User $client;

    private Company $company;

    private PayrollInvoiceSettlementProjector $projector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = PersonalManager::factory()->create();
        $this->client = User::factory()->create(['personal_manager_id' => $this->manager->id]);
        $this->company = Company::factory()->create(['user_id' => $this->client->id]);
        $this->projector = app(PayrollInvoiceSettlementProjector::class);
    }

    private function shipment(string $number, float $total, string $shippedOn = '2026-07-01', string $status = Shipment::PAYMENT_PAID): Shipment
    {
        return Shipment::factory()->create([
            'erp_number' => $number,
            'number' => $number,
            'user_id' => $this->client->id,
            'company_id' => $this->company->id,
            'total_amount' => $total,
            'currency_code' => 'RUB',
            'erp_created_at' => $shippedOn.' 10:00:00',
            'date' => $shippedOn,
            'payment_status' => $status,
        ]);
    }

    private function schedule(Shipment $shipment, string $dueOn, float $amount, float $settled = 0): SettlementEntry
    {
        return SettlementEntry::factory()->plan($amount, $settled)->create([
            'document_uuid' => $shipment->uuid,
            'document_number' => $shipment->erp_number,
            'date' => $dueOn,
            'user_id' => $this->client->id,
            'company_id' => $this->company->id,
        ]);
    }

    private function payment(string $objectName, string $date, float $amount, ?int $userId = null): SettlementEntry
    {
        return SettlementEntry::factory()->payment($amount)->create([
            'date' => $date,
            'user_id' => $userId ?? $this->client->id,
            'company_id' => $this->company->id,
            'settlement_object_kind' => 'ОбъектыРасчетов',
            'settlement_object_name' => $objectName,
            'document_number' => '29УТ-00'.random_int(1000, 9999),
        ]);
    }

    #[Test]
    #[TestDox('Частичные платежи: закрывает второй, задержка — рабочие дни от последней даты графика')]
    public function closing_payment_and_working_days(): void
    {
        $shipment = $this->shipment('29УТ-007699', 10000);
        $this->schedule($shipment, '2026-07-05', 3000, 3000);
        $this->schedule($shipment, '2026-07-10', 7000, 7000);   // последняя дата графика — срок
        $this->payment('Реализация товаров и услуг 29УТ-007699 от 01.07.2026 10:00:00', '2026-07-15', 4000);
        $this->payment('Реализация товаров и услуг 29УТ-007699 от 01.07.2026 10:00:00', '2026-07-22', 6000);

        $stats = $this->projector->projectPartners([$this->client->id], CarbonImmutable::parse('2026-01-01'));

        $this->assertSame(['shipments' => 1, 'matched' => 1, 'needs_review' => 0, 'managers' => [$this->manager->id]], $stats);

        $row = PayrollInvoiceSettlement::query()->where('shipment_id', $shipment->id)->firstOrFail();
        $this->assertSame('29YT-7699', $row->number_key);
        $this->assertSame('2026-07-10', $row->due_on->toDateString());
        $this->assertSame(PayrollInvoiceSettlement::DUE_SCHEDULE, $row->due_source);
        $this->assertSame('2026-07-22', $row->settled_on->toDateString());
        $this->assertSame(PayrollInvoiceSettlement::SOURCE_MATCHED, $row->settled_source);
        $this->assertSame(10000.0, (float) $row->matched_paid_amount);
        $this->assertSame(12, $row->delay_calendar_days);
        // 10.07.2026 — пятница; рабочие: 13–17 и 20–22 = 8.
        $this->assertSame(8, $row->delay_working_days);
        $this->assertCount(2, $row->payments);
        $this->assertSame($this->manager->id, $row->personal_manager_id);
        $this->assertFalse($row->needs_review);
    }

    #[Test]
    #[TestDox('Праздник из производственного календаря не считается рабочим днём')]
    public function holidays_are_skipped(): void
    {
        $shipment = $this->shipment('29УТ-000100', 5000, '2026-06-01');
        $this->schedule($shipment, '2026-06-10', 5000, 5000);
        $this->payment('Реализация товаров и услуг 29УТ-000100 от 01.06.2026 10:00:00', '2026-06-15', 5000);

        $row = $this->projector->projectShipment($shipment);

        // 11.06 — четверг (рабочий), 12.06 — праздник, 13–14 — выходные, 15.06 — понедельник.
        $this->assertSame(5, $row->delay_calendar_days);
        $this->assertSame(2, $row->delay_working_days);
        $this->assertSame(2, app(WorkingCalendar::class)->workingDaysBetween(
            CarbonImmutable::parse('2026-06-10'),
            CarbonImmutable::parse('2026-06-15'),
        ));
    }

    #[Test]
    #[TestDox('Оплата в срок или раньше — задержка ноль')]
    public function on_time_payment_has_zero_delay(): void
    {
        $shipment = $this->shipment('29УТ-000200', 5000);
        $this->schedule($shipment, '2026-07-31', 5000, 5000);
        $this->payment('Реализация товаров и услуг 29УТ-000200 от 01.07.2026 10:00:00', '2026-07-20', 5000);

        $row = $this->projector->projectShipment($shipment);

        $this->assertSame('2026-07-20', $row->settled_on->toDateString());
        $this->assertSame(0, $row->delay_calendar_days);
        $this->assertSame(0, $row->delay_working_days);
    }

    #[Test]
    #[TestDox('Оплачена по 1С, но платёж не сопоставлен — в очередь на ручную разметку')]
    public function paid_without_match_needs_review(): void
    {
        $shipment = $this->shipment('29УТ-000300', 5000);
        $this->schedule($shipment, '2026-07-10', 5000, 5000);
        // Платёж на заказ, а не на реализацию: аванс, задержки не бывает.
        $this->payment('Заказ клиента 29УТ-000300 от 20.06.2026 10:00:00', '2026-06-25', 5000);

        $row = $this->projector->projectShipment($shipment);

        $this->assertNull($row->settled_on);
        $this->assertNull($row->delay_working_days);
        $this->assertTrue($row->needs_review);
        $this->assertSame(0.0, (float) $row->matched_paid_amount);
    }

    #[Test]
    #[TestDox('Аванс по заказу закрывает реализацию датой отгрузки — задержки нет и разметка не нужна')]
    public function order_prepayment_closes_shipment_without_delay(): void
    {
        $order = \App\Models\Order::factory()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'erp_number' => '29УТ-013411',
            'user_id' => $this->client->id,
        ]);
        $shipment = $this->shipment('29УТ-000900', 5000, '2026-07-10');
        \App\Models\ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => \App\Models\Product::factory()->create()->id,
            'order_uuid' => $order->uuid,
            'quantity' => 1,
            'price' => 5000,
            'total' => 5000,
            'subtotal' => 5000,
        ]);
        $this->schedule($shipment, '2026-07-20', 5000, 5000);
        $this->payment('Заказ клиента 29УТ-013411 от 01.07.2026 12:00:00', '2026-07-03', 5000);

        $row = $this->projector->projectShipment($shipment);

        $this->assertSame('2026-07-10', $row->settled_on->toDateString());   // не раньше отгрузки
        $this->assertSame(PayrollInvoiceSettlement::SOURCE_MATCHED, $row->settled_source);
        $this->assertSame(0, $row->delay_working_days);
        $this->assertFalse($row->needs_review);
        $this->assertSame('order', $row->payments[0]['kind']);

        // Частичный аванс + доплата по накладной с задержкой: закрывает доплата.
        $late = $this->shipment('29УТ-000901', 8000, '2026-07-10');
        \App\Models\ShipmentItem::create([
            'shipment_id' => $late->id,
            'product_id' => \App\Models\Product::factory()->create()->id,
            'order_uuid' => $order->uuid,
            'quantity' => 1,
            'price' => 8000,
            'total' => 8000,
            'subtotal' => 8000,
        ]);
        $this->schedule($late, '2026-07-20', 8000, 8000);
        $this->payment('Реализация товаров и услуг 29УТ-000901 от 10.07.2026 10:00:00', '2026-07-24', 3000);

        $row = $this->projector->projectShipment($late);

        $this->assertSame('2026-07-24', $row->settled_on->toDateString());
        $this->assertSame(4, $row->delay_working_days);   // 21, 22, 23, 24 июля
    }

    #[Test]
    #[TestDox('Неоплаченная накладная не просится на разметку и не имеет задержки')]
    public function unpaid_is_not_flagged(): void
    {
        $shipment = $this->shipment('29УТ-000400', 5000, '2026-07-01', Shipment::PAYMENT_UNPAID);
        $this->schedule($shipment, '2026-07-10', 5000);

        $row = $this->projector->projectShipment($shipment);

        $this->assertNull($row->settled_on);
        $this->assertFalse($row->needs_review);
    }

    #[Test]
    #[TestDox('Ручная дата РОПа приоритетнее сопоставления и переживает ребилд')]
    public function manual_date_wins_and_survives_rebuild(): void
    {
        $head = User::factory()->create();
        $shipment = $this->shipment('29УТ-000500', 5000);
        $this->schedule($shipment, '2026-07-10', 5000, 5000);

        $row = $this->projector->projectShipment($shipment);
        $this->assertTrue($row->needs_review);

        $row = $this->projector->markManual($row, CarbonImmutable::parse('2026-07-14'), 'оплата зачётом', $head);

        $this->assertSame('2026-07-14', $row->settled_on->toDateString());
        $this->assertSame(PayrollInvoiceSettlement::SOURCE_MANUAL, $row->settled_source);
        $this->assertSame(2, $row->delay_working_days);   // 13.07 пн, 14.07 вт
        $this->assertFalse($row->needs_review);
        $this->assertSame($head->id, $row->manual_by_user_id);

        $this->projector->rebuild(CarbonImmutable::parse('2026-01-01'));

        $row->refresh();
        $this->assertSame('2026-07-14', $row->settled_on->toDateString());
        $this->assertSame(PayrollInvoiceSettlement::SOURCE_MANUAL, $row->settled_source);
        $this->assertSame('оплата зачётом', $row->manual_comment);

        $row = $this->projector->clearManual($row);
        $this->assertNull($row->settled_on);
        $this->assertTrue($row->needs_review);
    }

    #[Test]
    #[TestDox('Латиница и кириллица в префиксе номера сопоставляются')]
    public function latin_and_cyrillic_prefixes_match(): void
    {
        $shipment = $this->shipment('A2УТ-000768', 5000);   // латинская A, как в shipments
        $this->schedule($shipment, '2026-07-10', 5000, 5000);
        $this->payment('Реализация товаров и услуг А2УТ-000768 от 01.07.2026 10:00:00', '2026-07-13', 5000);   // кириллическая А

        $row = $this->projector->projectShipment($shipment);

        $this->assertSame('2026-07-13', $row->settled_on->toDateString());
        $this->assertSame(1, $row->delay_working_days);
    }

    #[Test]
    #[TestDox('Без графика срок берётся из колонки реализации')]
    public function due_falls_back_to_shipment_column(): void
    {
        $shipment = $this->shipment('29УТ-000600', 5000);
        $shipment->forceFill(['payment_due_date' => '2026-07-10'])->saveQuietly();
        $this->payment('Реализация товаров и услуг 29УТ-000600 от 01.07.2026 10:00:00', '2026-07-14', 5000);

        $row = $this->projector->projectShipment($shipment->fresh());

        $this->assertSame('2026-07-10', $row->due_on->toDateString());
        $this->assertSame(PayrollInvoiceSettlement::DUE_SHIPMENT_COLUMN, $row->due_source);
        $this->assertSame(2, $row->delay_working_days);
    }

    #[Test]
    #[TestDox('Чужие реализации в проекцию партнёра не попадают, ребилд считает статистику')]
    public function scope_and_rebuild_stats(): void
    {
        $other = User::factory()->create(['personal_manager_id' => PersonalManager::factory()->create()->id]);
        $mine = $this->shipment('29УТ-000700', 5000);
        $this->schedule($mine, '2026-07-10', 5000, 5000);
        $this->payment('Реализация товаров и услуг 29УТ-000700 от 01.07.2026 10:00:00', '2026-07-11', 5000);

        Shipment::factory()->create([
            'erp_number' => '29УТ-000701', 'user_id' => $other->id, 'total_amount' => 100,
            'erp_created_at' => '2026-07-01 10:00:00', 'payment_status' => Shipment::PAYMENT_PAID, 'currency_code' => 'RUB',
        ]);

        $this->projector->projectPartners([$this->client->id], CarbonImmutable::parse('2026-01-01'));
        $this->assertSame(1, PayrollInvoiceSettlement::query()->count());

        $stats = $this->projector->rebuild(CarbonImmutable::parse('2026-01-01'));
        $this->assertSame(2, $stats['shipments']);
        $this->assertSame(1, $stats['matched']);
        $this->assertSame(1, $stats['needs_review']);
        $this->assertCount(2, $stats['managers']);
    }

    #[Test]
    #[TestDox('Событие регистра ставит джоб проекции, джоб бросает событие пересчёта')]
    public function settlement_event_triggers_projection_job(): void
    {
        Queue::fake();

        PartnerSettlementsChanged::dispatch([$this->client->id], 'settlement.posted');

        Queue::assertPushed(ProjectInvoiceSettlements::class, fn (ProjectInvoiceSettlements $job): bool => $job->userIds === [$this->client->id]);

        $shipment = $this->shipment('29УТ-000800', 5000);
        $this->schedule($shipment, '2026-07-10', 5000, 5000);

        Event::fake([PayrollInputsChanged::class]);

        (new ProjectInvoiceSettlements([$this->client->id], 'settlement.posted'))->handle($this->projector);

        Event::assertDispatched(PayrollInputsChanged::class, fn (PayrollInputsChanged $e): bool => $e->managerIds === [$this->manager->id]);
    }
}
