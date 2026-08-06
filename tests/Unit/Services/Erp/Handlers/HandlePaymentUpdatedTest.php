<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\Payment;
use App\Models\Shipment;
use App\Services\Erp\ErpHandlerOutcome;
use App\Services\Erp\Handlers\HandlePaymentCreated;
use App\Services\Erp\Handlers\HandlePaymentUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandlePaymentUpdatedTest extends TestCase
{
    use RefreshDatabase;

    private const PAYMENT_UUID = 'p1a2b3c4-d5e6-7890-abcd-ef1234567890';

    private const CONTRACTOR_UUID = 'c1a2b3c4-d5e6-7890-abcd-ef1234567890';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'event' => 'payment.updated',
            'uuid' => self::PAYMENT_UUID,
            'number' => '29УТ-002488',
            'date' => '2026-07-30T23:59:59+03:00',
            'direction' => 'in',
            'contractor_uuid' => self::CONTRACTOR_UUID,
            'amount' => 2000.00,
            'currency_code' => 'RUB',
        ], $overrides);
    }

    private function createPaymentWithAllocation(Shipment $shipment, float $amount = 1200.00): Payment
    {
        (new HandlePaymentCreated)->handle($this->payload([
            'event' => 'payment.created',
            'allocations' => [['shipment_uuid' => $shipment->uuid, 'amount' => $amount]],
        ]));

        return Payment::where('uuid', self::PAYMENT_UUID)->firstOrFail();
    }

    #[Test]
    public function it_replaces_allocations_entirely(): void
    {
        $first = Shipment::factory()->create(['currency_code' => 'RUB', 'total_amount' => 5000.00]);
        $second = Shipment::factory()->create(['currency_code' => 'RUB', 'total_amount' => 3000.00]);

        $this->createPaymentWithAllocation($first);

        (new HandlePaymentUpdated)->handle($this->payload([
            'allocations' => [['shipment_uuid' => $second->uuid, 'amount' => 800.00]],
        ]));

        $payment = Payment::where('uuid', self::PAYMENT_UUID)->firstOrFail();
        $this->assertCount(1, $payment->allocations);
        $this->assertSame($second->uuid, $payment->allocations->first()->shipment_uuid);

        // Снятое разнесение не должно оставить на первой реализации оплату-призрака.
        $this->assertEquals(0.00, (float) $first->fresh()->paid_amount);
        $this->assertSame(Shipment::PAYMENT_UNPAID, $first->fresh()->payment_status);
        $this->assertEquals(800.00, (float) $second->fresh()->paid_amount);
    }

    #[Test]
    public function it_keeps_allocations_when_key_is_absent(): void
    {
        $shipment = Shipment::factory()->create(['currency_code' => 'RUB', 'total_amount' => 5000.00]);
        $this->createPaymentWithAllocation($shipment);

        (new HandlePaymentUpdated)->handle($this->payload(['number' => '29УТ-999999']));

        $payment = Payment::where('uuid', self::PAYMENT_UUID)->firstOrFail();
        $this->assertSame('29УТ-999999', $payment->number);
        $this->assertCount(1, $payment->allocations, 'Отсутствие ключа allocations не должно очищать разнесение');
        $this->assertEquals(1200.00, (float) $shipment->fresh()->paid_amount);
    }

    #[Test]
    public function it_clears_allocations_on_empty_array(): void
    {
        $shipment = Shipment::factory()->create(['currency_code' => 'RUB', 'total_amount' => 5000.00]);
        $this->createPaymentWithAllocation($shipment);

        (new HandlePaymentUpdated)->handle($this->payload(['allocations' => []]));

        $payment = Payment::where('uuid', self::PAYMENT_UUID)->firstOrFail();
        $this->assertCount(0, $payment->allocations);
        $this->assertEquals(2000.00, (float) $payment->unallocated_amount, 'Платёж целиком стал авансом');
        $this->assertEquals(0.00, (float) $shipment->fresh()->paid_amount);
    }

    #[Test]
    public function it_creates_unknown_payment_and_marks_outcome_recovered(): void
    {
        (new HandlePaymentUpdated)->handle($this->payload());

        $this->assertDatabaseHas('payments', [
            'uuid' => self::PAYMENT_UUID,
            'number' => '29УТ-002488',
            'amount' => 2000.00,
        ]);

        $this->assertSame(ErpHandlerOutcome::STATUS_RECOVERED, app(ErpHandlerOutcome::class)->status());
    }

    #[Test]
    public function it_recalculates_advance_when_amount_changes_without_allocations(): void
    {
        $shipment = Shipment::factory()->create(['currency_code' => 'RUB', 'total_amount' => 5000.00]);
        $this->createPaymentWithAllocation($shipment);

        (new HandlePaymentUpdated)->handle($this->payload(['amount' => 5000.00]));

        $payment = Payment::where('uuid', self::PAYMENT_UUID)->firstOrFail();
        $this->assertEquals(1200.00, (float) $payment->allocated_amount);
        $this->assertEquals(3800.00, (float) $payment->unallocated_amount);
    }
}
