<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\Payment;
use App\Models\Shipment;
use App\Services\Erp\Handlers\HandlePaymentCreated;
use App\Services\Erp\Handlers\HandlePaymentDeleted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandlePaymentDeletedTest extends TestCase
{
    use RefreshDatabase;

    private const PAYMENT_UUID = 'p1a2b3c4-d5e6-7890-abcd-ef1234567890';

    private function createPaidShipment(): Shipment
    {
        $shipment = Shipment::factory()->create(['currency_code' => 'RUB', 'total_amount' => 1200.00]);

        (new HandlePaymentCreated)->handle([
            'event' => 'payment.created',
            'uuid' => self::PAYMENT_UUID,
            'number' => '29УТ-002488',
            'date' => '2026-07-30T23:59:59+03:00',
            'direction' => 'in',
            'contractor_uuid' => 'c1a2b3c4-d5e6-7890-abcd-ef1234567890',
            'amount' => 1200.00,
            'currency_code' => 'RUB',
            'allocations' => [['shipment_uuid' => $shipment->uuid, 'amount' => 1200.00]],
        ]);

        $shipment->refresh();
        $this->assertSame(Shipment::PAYMENT_PAID, $shipment->payment_status);

        return $shipment;
    }

    #[Test]
    public function it_soft_deletes_payment_and_resets_shipment_payment(): void
    {
        $shipment = $this->createPaidShipment();

        (new HandlePaymentDeleted)->handle(['event' => 'payment.deleted', 'uuid' => self::PAYMENT_UUID]);

        $this->assertSoftDeleted('payments', ['uuid' => self::PAYMENT_UUID]);

        $shipment->refresh();
        $this->assertEquals(0.00, (float) $shipment->paid_amount);
        $this->assertSame(Shipment::PAYMENT_UNPAID, $shipment->payment_status);
        $this->assertNull($shipment->paid_at);
    }

    #[Test]
    public function it_keeps_allocation_rows_so_reposting_restores_payment(): void
    {
        $shipment = $this->createPaidShipment();
        $payment = Payment::where('uuid', self::PAYMENT_UUID)->firstOrFail();

        (new HandlePaymentDeleted)->handle(['event' => 'payment.deleted', 'uuid' => self::PAYMENT_UUID]);

        $this->assertDatabaseHas('payment_allocations', [
            'payment_id' => $payment->id,
            'shipment_id' => $shipment->id,
        ]);

        // Повторное проведение без расшифровки: разнесение возвращается как было.
        (new HandlePaymentCreated)->handle([
            'event' => 'payment.created',
            'uuid' => self::PAYMENT_UUID,
            'number' => '29УТ-002488',
            'date' => '2026-07-30T23:59:59+03:00',
            'direction' => 'in',
            'contractor_uuid' => 'c1a2b3c4-d5e6-7890-abcd-ef1234567890',
            'amount' => 1200.00,
            'currency_code' => 'RUB',
        ]);

        $this->assertNull(Payment::where('uuid', self::PAYMENT_UUID)->firstOrFail()->deleted_at);

        // Восстановление платежа само по себе оплату не пересчитывает — это делает
        // ближайший пересчёт по реализации; проверяем именно его.
        app(\App\Services\Payments\PaymentAllocationService::class)->recalculateShipments([$shipment->id]);

        $this->assertEquals(1200.00, (float) $shipment->fresh()->paid_amount);
    }

    #[Test]
    public function it_is_idempotent_and_tolerates_unknown_uuid(): void
    {
        $this->createPaidShipment();

        (new HandlePaymentDeleted)->handle(['event' => 'payment.deleted', 'uuid' => self::PAYMENT_UUID]);
        (new HandlePaymentDeleted)->handle(['event' => 'payment.deleted', 'uuid' => self::PAYMENT_UUID]);
        (new HandlePaymentDeleted)->handle(['event' => 'payment.deleted', 'uuid' => 'never-existed']);

        $this->assertSoftDeleted('payments', ['uuid' => self::PAYMENT_UUID]);
    }
}
