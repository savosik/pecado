<?php

namespace Tests\Feature\Erp;

use App\Models\Payment;
use App\Models\Shipment;
use App\Services\Erp\Handlers\HandlePaymentCreated;
use App\Services\Erp\Handlers\HandleShipmentCreated;
use App\Services\Erp\Handlers\HandleShipmentUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Связь строк расшифровки платежа с реализациями сайта.
 *
 * Платежи (`erp_in.payments`) и реализации (`erp_in.documents`) идут разными
 * очередями без гарантии порядка, поэтому проверяются обе гонки: реализация
 * раньше платежа и платёж раньше реализации. `shipment_uuid` при этом остаётся
 * источником правды и сохраняется всегда — даже когда реализации на сайте нет.
 */
class PaymentAllocationLinkTest extends TestCase
{
    use RefreshDatabase;

    private const PAYMENT_UUID = '550e8400-e29b-41d4-a716-446655440077';

    private const SHIPMENT_UUID = '550e8400-e29b-41d4-a716-446655440005';

    private function publishPayment(float $amount = 1200.00): void
    {
        (new HandlePaymentCreated)->handle([
            'event' => 'payment.created',
            'uuid' => self::PAYMENT_UUID,
            'number' => '29УТ-002488',
            'date' => '2026-07-30T23:59:59+03:00',
            'direction' => 'in',
            'contractor_uuid' => '550e8400-e29b-41d4-a716-446655440043',
            'amount' => $amount,
            'currency_code' => 'RUB',
            'allocations' => [['shipment_uuid' => self::SHIPMENT_UUID, 'amount' => $amount]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function shipmentPayload(array $overrides = []): array
    {
        return array_merge([
            'event' => 'shipment.created',
            'uuid' => self::SHIPMENT_UUID,
            'number' => '29УТ-003413',
            'contractor_uuid' => '550e8400-e29b-41d4-a716-446655440043',
            'date' => '2026-07-29',
            'status' => 'completed',
            'currency_code' => 'RUB',
            'items' => [['product_uuid' => 'missing-product', 'quantity' => 1, 'price' => 1200.00, 'total' => 1200.00]],
        ], $overrides);
    }

    #[Test]
    public function payment_arriving_before_shipment_is_linked_when_shipment_comes(): void
    {
        $this->publishPayment();

        $allocation = Payment::where('uuid', self::PAYMENT_UUID)->firstOrFail()->allocations->first();
        $this->assertNull($allocation->shipment_id, 'Реализации ещё нет — строка сохраняется без привязки');
        $this->assertSame(self::SHIPMENT_UUID, $allocation->shipment_uuid);

        (new HandleShipmentCreated)->handle($this->shipmentPayload());

        $shipment = Shipment::where('uuid', self::SHIPMENT_UUID)->firstOrFail();

        $this->assertSame($shipment->id, $allocation->fresh()->shipment_id);
        $this->assertEquals(1200.00, (float) $shipment->paid_amount);
        $this->assertSame(Shipment::PAYMENT_PAID, $shipment->payment_status);
    }

    #[Test]
    public function shipment_arriving_before_payment_gets_paid_immediately(): void
    {
        (new HandleShipmentCreated)->handle($this->shipmentPayload());

        $this->publishPayment();

        $shipment = Shipment::where('uuid', self::SHIPMENT_UUID)->firstOrFail();

        $this->assertEquals(1200.00, (float) $shipment->paid_amount);
        $this->assertSame(Shipment::PAYMENT_PAID, $shipment->payment_status);
    }

    #[Test]
    public function shipment_updated_also_links_orphan_allocations(): void
    {
        // Реализация уже есть, но строка осиротела — например, разнесение приехало
        // до деплоя фичи и осталось без FK.
        (new HandleShipmentCreated)->handle($this->shipmentPayload());
        $shipment = Shipment::where('uuid', self::SHIPMENT_UUID)->firstOrFail();

        $this->publishPayment();
        Payment::where('uuid', self::PAYMENT_UUID)->firstOrFail()
            ->allocations()->update(['shipment_id' => null]);

        (new HandleShipmentUpdated)->handle($this->shipmentPayload(['event' => 'shipment.updated']));

        $allocation = Payment::where('uuid', self::PAYMENT_UUID)->firstOrFail()->allocations->first();
        $this->assertSame($shipment->id, $allocation->shipment_id);
        $this->assertEquals(1200.00, (float) $shipment->fresh()->paid_amount);
    }
}
