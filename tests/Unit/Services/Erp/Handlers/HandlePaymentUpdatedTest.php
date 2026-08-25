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

    /**
     * `allocations` игнорируются (v16.0.0/fin-11): обновление платежа
     * не трогает оплату реализаций — её считает регистр.
     */
    #[Test]
    public function it_ignores_legacy_allocations_key(): void
    {
        $shipment = Shipment::factory()->create(['currency_code' => 'RUB', 'total_amount' => 5000.00]);

        (new HandlePaymentCreated)->handle($this->payload(['event' => 'payment.created']));

        (new HandlePaymentUpdated)->handle($this->payload([
            'number' => '29УТ-999999',
            'allocations' => [['shipment_uuid' => $shipment->uuid, 'amount' => 800.00]],
        ]));

        $payment = Payment::where('uuid', self::PAYMENT_UUID)->firstOrFail();
        $this->assertSame('29УТ-999999', $payment->number);
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
}
