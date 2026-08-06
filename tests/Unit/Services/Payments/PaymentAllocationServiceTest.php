<?php

namespace Tests\Unit\Services\Payments;

use App\Models\Payment;
use App\Models\Shipment;
use App\Services\Payments\PaymentAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Денежные агрегаты — денормализация, и её единственный писатель здесь.
 *
 * Ключевое свойство, которое проверяют эти тесты: пересчёт — полная функция
 * от состояния БД, а не инкремент. Иначе повторная доставка сообщения из
 * RabbitMQ удваивала бы оплату.
 */
class PaymentAllocationServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentAllocationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PaymentAllocationService::class);
    }

    private function payment(float $amount, string $direction = Payment::DIRECTION_IN, string $currency = 'RUB'): Payment
    {
        return Payment::factory()->create([
            'amount' => $amount,
            'direction' => $direction,
            'currency_code' => $currency,
        ]);
    }

    private function shipment(float $total, string $currency = 'RUB'): Shipment
    {
        return Shipment::factory()->create(['total_amount' => $total, 'currency_code' => $currency]);
    }

    #[Test]
    public function it_computes_allocated_and_unallocated_amounts(): void
    {
        $payment = $this->payment(2325.20);
        $shipment = $this->shipment(5000.00);

        $this->service->sync($payment, [
            ['shipment_uuid' => $shipment->uuid, 'amount' => 1200.00],
            ['shipment_uuid' => $shipment->uuid, 'amount' => 125.20],
        ]);

        $payment->refresh();
        $this->assertEquals(1325.20, (float) $payment->allocated_amount);
        $this->assertEquals(1000.00, (float) $payment->unallocated_amount);
        $this->assertSame(Payment::ALLOCATION_PARTIAL, $payment->allocation_status);
    }

    #[Test]
    public function it_marks_payment_without_allocations_as_advance(): void
    {
        $payment = $this->payment(1000.00);
        $this->service->sync($payment, []);

        $payment->refresh();
        $this->assertSame(Payment::ALLOCATION_ADVANCE, $payment->allocation_status);
        $this->assertSame('Аванс', $payment->allocation_status_label);
        $this->assertTrue($payment->is_advance);
    }

    #[Test]
    public function shipment_closed_by_two_payments_becomes_paid(): void
    {
        $shipment = $this->shipment(5000.00);

        $this->service->sync($this->payment(3000.00), [['shipment_uuid' => $shipment->uuid, 'amount' => 3000.00]]);
        $shipment->refresh();
        $this->assertSame(Shipment::PAYMENT_PARTIAL, $shipment->payment_status);

        $this->service->sync($this->payment(2000.00), [['shipment_uuid' => $shipment->uuid, 'amount' => 2000.00]]);
        $shipment->refresh();

        $this->assertEquals(5000.00, (float) $shipment->paid_amount);
        $this->assertSame(Shipment::PAYMENT_PAID, $shipment->payment_status);
        $this->assertNotNull($shipment->paid_at);
        $this->assertEquals(0.00, $shipment->unpaid_amount);
    }

    #[Test]
    public function one_payment_can_close_several_shipments(): void
    {
        $first = $this->shipment(1000.00);
        $second = $this->shipment(2000.00);

        $this->service->sync($this->payment(3000.00), [
            ['shipment_uuid' => $first->uuid, 'amount' => 1000.00],
            ['shipment_uuid' => $second->uuid, 'amount' => 2000.00],
        ]);

        $this->assertSame(Shipment::PAYMENT_PAID, $first->fresh()->payment_status);
        $this->assertSame(Shipment::PAYMENT_PAID, $second->fresh()->payment_status);
    }

    #[Test]
    public function overpayment_is_marked_separately(): void
    {
        $shipment = $this->shipment(1000.00);

        $this->service->sync($this->payment(1500.00), [['shipment_uuid' => $shipment->uuid, 'amount' => 1500.00]]);

        $shipment->refresh();
        $this->assertSame(Shipment::PAYMENT_OVERPAID, $shipment->payment_status);
        $this->assertSame('Переплата', $shipment->payment_status_label);
        // Переплата не создаёт отрицательного остатка к оплате.
        $this->assertEquals(0.00, $shipment->unpaid_amount);
    }

    #[Test]
    public function kopeck_shortfall_still_counts_as_paid(): void
    {
        $shipment = $this->shipment(1000.00);

        $this->service->sync($this->payment(999.99), [['shipment_uuid' => $shipment->uuid, 'amount' => 999.99]]);

        $this->assertSame(Shipment::PAYMENT_PAID, $shipment->fresh()->payment_status);
    }

    #[Test]
    public function refund_reduces_paid_amount(): void
    {
        $shipment = $this->shipment(5000.00);

        $this->service->sync($this->payment(5000.00), [['shipment_uuid' => $shipment->uuid, 'amount' => 5000.00]]);
        $this->assertSame(Shipment::PAYMENT_PAID, $shipment->fresh()->payment_status);

        $refund = $this->payment(2000.00, Payment::DIRECTION_OUT);
        $this->service->sync($refund, [['shipment_uuid' => $shipment->uuid, 'amount' => 2000.00]]);

        $shipment->refresh();
        $this->assertEquals(3000.00, (float) $shipment->paid_amount);
        $this->assertSame(Shipment::PAYMENT_PARTIAL, $shipment->payment_status);
    }

    #[Test]
    public function payment_in_another_currency_is_not_counted(): void
    {
        $shipment = $this->shipment(5000.00, 'RUB');
        $payment = $this->payment(5000.00, Payment::DIRECTION_IN, 'KZT');

        $this->service->sync($payment, [['shipment_uuid' => $shipment->uuid, 'amount' => 5000.00]]);

        $shipment->refresh();
        $this->assertEquals(0.00, (float) $shipment->paid_amount);
        $this->assertSame(Shipment::PAYMENT_UNPAID, $shipment->payment_status);

        // Строка при этом сохранена: связь есть, в оплату она просто не идёт.
        $this->assertCount(1, $payment->fresh()->allocations);
    }

    #[Test]
    public function deleted_payment_is_not_counted(): void
    {
        $shipment = $this->shipment(1000.00);
        $payment = $this->payment(1000.00);

        $this->service->sync($payment, [['shipment_uuid' => $shipment->uuid, 'amount' => 1000.00]]);
        $payment->delete();

        $this->service->recalculateShipments([$shipment->id]);

        $this->assertEquals(0.00, (float) $shipment->fresh()->paid_amount);
    }

    #[Test]
    public function recalculation_is_idempotent(): void
    {
        $shipment = $this->shipment(5000.00);
        $payment = $this->payment(1200.00);

        $this->service->sync($payment, [['shipment_uuid' => $shipment->uuid, 'amount' => 1200.00]]);

        $this->service->recalculateShipments([$shipment->id]);
        $this->service->recalculateShipments([$shipment->id]);
        $this->service->recalculatePayment($payment->fresh());

        $this->assertEquals(1200.00, (float) $shipment->fresh()->paid_amount);
        $this->assertEquals(1200.00, (float) $payment->fresh()->allocated_amount);
    }

    #[Test]
    public function it_links_orphan_allocations_when_shipment_arrives(): void
    {
        $payment = $this->payment(1000.00);
        $shipmentUuid = 'late-shipment-uuid-0001';

        $this->service->sync($payment, [['shipment_uuid' => $shipmentUuid, 'amount' => 1000.00]]);
        $this->assertNull($payment->fresh()->allocations->first()->shipment_id);

        $shipment = Shipment::factory()->create([
            'uuid' => $shipmentUuid,
            'total_amount' => 1000.00,
            'currency_code' => 'RUB',
        ]);

        $linked = $this->service->linkOrphanAllocations($shipment);

        $this->assertSame(1, $linked);
        $this->assertSame($shipment->id, $payment->fresh()->allocations->first()->shipment_id);
        $this->assertSame(Shipment::PAYMENT_PAID, $shipment->fresh()->payment_status);
    }
}
