<?php

namespace Tests\Unit\Services\Payments;

use App\Models\Payment;
use App\Models\Shipment;
use App\Models\ShipmentPaymentSchedule;
use App\Services\Payments\PaymentAllocationService;
use App\Services\Payments\PaymentScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * График оплаты и его погашение фактом.
 *
 * Ключевое свойство, которое проверяют эти тесты: раскладка — полная функция
 * от суммы оплаты и состава графика, а не инкремент. Иначе повторная доставка
 * сообщения из RabbitMQ гасила бы строки дважды.
 */
class PaymentScheduleServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentScheduleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PaymentScheduleService::class);
    }

    private function shipment(float $total = 10000.00, float $paid = 0.0): Shipment
    {
        return Shipment::factory()->create([
            'total_amount' => $total,
            'paid_amount' => $paid,
            'currency_code' => 'RUB',
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function twoLines(): array
    {
        return [
            ['line_number' => 1, 'due_date' => '2026-08-27', 'amount' => 3000.00],
            ['line_number' => 2, 'due_date' => '2026-09-27', 'amount' => 7000.00],
        ];
    }

    #[Test]
    public function it_saves_schedule_lines_from_erp_payload(): void
    {
        $shipment = $this->shipment();

        $this->service->sync($shipment, [[
            'line_number' => 1,
            'due_date' => '2026-08-27',
            'amount' => 2325.20,
            'percent' => 100,
            'term_days' => 30,
            'basis' => 'shipment_date',
            'basis_name' => 'от даты отгрузки',
            'stage' => 'credit',
            'stage_name' => 'Оплата после отгрузки',
            'order_uuid' => 'ord-uuid-0001',
        ]]);

        $line = $shipment->paymentSchedules()->sole();

        $this->assertSame(1, $line->line_number);
        $this->assertSame('2026-08-27', $line->due_date->toDateString());
        $this->assertEquals(2325.20, (float) $line->amount);
        $this->assertEquals(100.0, (float) $line->percent);
        $this->assertSame(30, $line->term_days);
        $this->assertSame('shipment_date', $line->basis);
        $this->assertSame('от даты отгрузки', $line->basis_name);
        $this->assertSame('credit', $line->stage);
        $this->assertSame('ord-uuid-0001', $line->order_uuid);
        $this->assertSame('2026-08-27', $shipment->fresh()->payment_due_date->toDateString());
    }

    #[Test]
    public function it_replaces_schedule_entirely_instead_of_appending(): void
    {
        $shipment = $this->shipment();

        $this->service->sync($shipment, $this->twoLines());
        $this->service->sync($shipment, $this->twoLines());

        // Повторная доставка того же сообщения не задваивает строки:
        // замена идёт delete-and-recreate, как у позиций документа.
        $this->assertSame(2, $shipment->paymentSchedules()->count());
    }

    #[Test]
    public function it_clears_schedule_on_empty_array(): void
    {
        $shipment = $this->shipment();
        $this->service->sync($shipment, $this->twoLines());

        $this->service->sync($shipment, []);

        $this->assertSame(0, $shipment->paymentSchedules()->count());
        $this->assertNull($shipment->fresh()->payment_due_date);
    }

    #[Test]
    public function it_skips_lines_without_due_date(): void
    {
        $shipment = $this->shipment();

        $this->service->sync($shipment, [
            ['line_number' => 1, 'amount' => 3000.00],
            ['line_number' => 2, 'due_date' => '2026-09-27', 'amount' => 7000.00],
        ]);

        // Строка без плановой даты бессмысленна для календаря, но не должна
        // ронять остальной график.
        $line = $shipment->paymentSchedules()->sole();
        $this->assertSame('2026-09-27', $line->due_date->toDateString());
    }

    #[Test]
    public function it_drops_unknown_enum_codes_but_keeps_russian_names(): void
    {
        $shipment = $this->shipment();

        $this->service->sync($shipment, [[
            'due_date' => '2026-08-27',
            'amount' => 1000.00,
            'basis' => 'от даты отгрузки',
            'basis_name' => 'от даты отгрузки',
            'stage' => 'ПослеОтгрузки',
            'stage_name' => 'Оплата после отгрузки',
        ]]);

        $line = $shipment->paymentSchedules()->sole();

        // На коды опирается логика — «почти правильное» значение молча ломало бы
        // фильтры, поэтому оно обнуляется. Русский текст показывается как есть.
        $this->assertNull($line->basis);
        $this->assertNull($line->stage);
        $this->assertSame('от даты отгрузки', $line->basis_name);
        $this->assertSame('Оплата после отгрузки', $line->stage_name);
    }

    #[Test]
    public function it_numbers_lines_by_position_when_erp_omits_line_number(): void
    {
        $shipment = $this->shipment();

        $this->service->sync($shipment, [
            ['due_date' => '2026-08-27', 'amount' => 3000.00],
            ['due_date' => '2026-09-27', 'amount' => 7000.00],
        ]);

        $this->assertSame([1, 2], $shipment->paymentSchedules()->pluck('line_number')->all());
    }

    #[Test]
    public function partial_payment_closes_first_line_and_splits_the_next(): void
    {
        $shipment = $this->shipment(total: 10000.00, paid: 4500.00);
        $this->service->sync($shipment, $this->twoLines());

        $lines = $shipment->paymentSchedules()->get();

        $this->assertEquals(3000.00, (float) $lines[0]->paid_amount);
        $this->assertEquals(1500.00, (float) $lines[1]->paid_amount);
        $this->assertSame(ShipmentPaymentSchedule::STATUS_PAID, $lines[0]->status);
        $this->assertSame(ShipmentPaymentSchedule::STATUS_PARTIAL, $lines[1]->status);
        // Ближайшая дата — первая непокрытая строка, а не первая вообще.
        $this->assertSame('2026-09-27', $shipment->fresh()->payment_due_date->toDateString());
    }

    #[Test]
    public function full_payment_closes_every_line_and_clears_due_date(): void
    {
        $shipment = $this->shipment(total: 10000.00, paid: 10000.00);
        $this->service->sync($shipment, $this->twoLines());

        $this->assertSame(0, $shipment->paymentSchedules()->outstanding()->count());
        $this->assertNull($shipment->fresh()->payment_due_date);
    }

    #[Test]
    public function overpayment_never_exceeds_line_amount(): void
    {
        $shipment = $this->shipment(total: 10000.00, paid: 15000.00);
        $this->service->sync($shipment, $this->twoLines());

        $lines = $shipment->paymentSchedules()->get();

        // Переплата не «переливается» в отрицательный остаток и не раздувает
        // последнюю строку — платить по графику больше, чем в нём написано, нечего.
        $this->assertEquals(3000.00, (float) $lines[0]->paid_amount);
        $this->assertEquals(7000.00, (float) $lines[1]->paid_amount);
        $this->assertNull($shipment->fresh()->payment_due_date);
    }

    #[Test]
    public function redistribution_is_idempotent(): void
    {
        $shipment = $this->shipment(total: 10000.00, paid: 4500.00);
        $this->service->sync($shipment, $this->twoLines());

        $this->service->redistribute($shipment);
        $this->service->redistribute($shipment);

        $this->assertEquals([3000.00, 1500.00], $shipment->paymentSchedules()->pluck('paid_amount')
            ->map(fn ($value) => (float) $value)->all());
    }

    #[Test]
    public function fifo_order_follows_due_date_not_line_number(): void
    {
        $shipment = $this->shipment(total: 10000.00, paid: 1000.00);

        // 1С вправе пронумеровать строки не по возрастанию даты: гасить всё равно
        // нужно ту, что наступает раньше — её клиент и видит первой.
        $this->service->sync($shipment, [
            ['line_number' => 1, 'due_date' => '2026-09-27', 'amount' => 7000.00],
            ['line_number' => 2, 'due_date' => '2026-08-27', 'amount' => 3000.00],
        ]);

        $first = $shipment->paymentSchedules()->first();
        $this->assertSame('2026-08-27', $first->due_date->toDateString());
        $this->assertEquals(1000.00, (float) $first->paid_amount);
        $this->assertSame('2026-08-27', $shipment->fresh()->payment_due_date->toDateString());
    }

    #[Test]
    public function payment_allocation_pushes_schedule_forward(): void
    {
        $shipment = $this->shipment();
        $this->service->sync($shipment, $this->twoLines());

        $payment = Payment::factory()->create([
            'amount' => 3000.00,
            'direction' => Payment::DIRECTION_IN,
            'currency_code' => 'RUB',
        ]);

        // Пересчёт графика должен подхватываться самим фактом оплаты,
        // без отдельного вызова из обработчика платежа.
        app(PaymentAllocationService::class)->sync($payment, [
            ['shipment_uuid' => $shipment->uuid, 'amount' => 3000.00],
        ]);

        $lines = $shipment->paymentSchedules()->get();
        $this->assertEquals(3000.00, (float) $lines[0]->paid_amount);
        $this->assertEquals(0.00, (float) $lines[1]->paid_amount);
        $this->assertSame('2026-09-27', $shipment->fresh()->payment_due_date->toDateString());
    }

    #[Test]
    public function refund_reopens_a_closed_line(): void
    {
        $shipment = $this->shipment();
        $this->service->sync($shipment, $this->twoLines());

        $income = Payment::factory()->create([
            'amount' => 3000.00,
            'direction' => Payment::DIRECTION_IN,
            'currency_code' => 'RUB',
        ]);
        $allocations = app(PaymentAllocationService::class);
        $allocations->sync($income, [['shipment_uuid' => $shipment->uuid, 'amount' => 3000.00]]);

        $refund = Payment::factory()->create([
            'amount' => 1000.00,
            'direction' => Payment::DIRECTION_OUT,
            'currency_code' => 'RUB',
        ]);
        $allocations->sync($refund, [['shipment_uuid' => $shipment->uuid, 'amount' => 1000.00]]);

        // Возврат уменьшает оплату реализации — значит, снова открывает строку,
        // которая уже считалась закрытой.
        $first = $shipment->paymentSchedules()->first();
        $this->assertEquals(2000.00, (float) $first->paid_amount);
        $this->assertSame(ShipmentPaymentSchedule::STATUS_PARTIAL, $first->status);
        $this->assertSame('2026-08-27', $shipment->fresh()->payment_due_date->toDateString());
    }

    #[Test]
    public function overdue_flag_is_derived_from_due_date_and_paid_amount(): void
    {
        $shipment = $this->shipment();
        $this->service->sync($shipment, [
            ['due_date' => now()->subDays(5)->toDateString(), 'amount' => 3000.00],
            ['due_date' => now()->addDays(5)->toDateString(), 'amount' => 7000.00],
        ]);

        $lines = $shipment->paymentSchedules()->get();
        $this->assertTrue($lines[0]->is_overdue);
        $this->assertSame('Просрочено', $lines[0]->status_label);
        $this->assertFalse($lines[1]->is_overdue);
        $this->assertTrue($shipment->fresh()->is_payment_overdue);
    }

    #[Test]
    public function fully_paid_overdue_line_is_not_overdue(): void
    {
        $shipment = $this->shipment(total: 3000.00, paid: 3000.00);
        $this->service->sync($shipment, [
            ['due_date' => now()->subDays(5)->toDateString(), 'amount' => 3000.00],
        ]);

        $this->assertFalse($shipment->paymentSchedules()->sole()->is_overdue);
        $this->assertFalse($shipment->fresh()->is_payment_overdue);
    }
}
