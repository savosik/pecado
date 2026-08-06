<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\Company;
use App\Models\Payment;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Erp\Handlers\HandlePaymentCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandlePaymentCreatedTest extends TestCase
{
    use RefreshDatabase;

    private const PAYMENT_UUID = 'p1a2b3c4-d5e6-7890-abcd-ef1234567890';

    private const CONTRACTOR_UUID = 'c1a2b3c4-d5e6-7890-abcd-ef1234567890';

    private const PARTNER_UUID = 'u1a2b3c4-d5e6-7890-abcd-ef1234567890';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'event' => 'payment.created',
            'uuid' => self::PAYMENT_UUID,
            'number' => '29УТ-002488',
            'date' => '2026-07-30T23:59:59+03:00',
            'direction' => 'in',
            'contractor_uuid' => self::CONTRACTOR_UUID,
            'amount' => 2325.20,
            'currency_code' => 'RUB',
        ], $overrides);
    }

    #[Test]
    public function it_creates_payment_with_all_document_details(): void
    {
        (new HandlePaymentCreated)->handle($this->payload([
            'operation_code' => 'customer_payment',
            'operation_name' => 'Поступление оплаты от клиента',
            'document_type' => 'Платежное поручение',
            'tax_id' => '7710140679',
            'organization_account' => ['number' => '2693', 'bank_name' => 'ПАО СБЕРБАНК'],
            'payer_account' => ['number' => '40802810', 'bank_name' => 'ООО «Банк Точка»'],
            'bank_number' => '9202',
            'bank_date' => '2026-07-30',
            'bank_confirmed' => true,
            'uip' => '0',
            'purpose' => 'Оплата по счёту №123',
        ]));

        $this->assertDatabaseHas('payments', [
            'uuid' => self::PAYMENT_UUID,
            'number' => '29УТ-002488',
            'direction' => 'in',
            'operation_code' => 'customer_payment',
            'document_type' => 'Платежное поручение',
            'contractor_uuid' => self::CONTRACTOR_UUID,
            'tax_id' => '7710140679',
            'organization_account' => '2693',
            'organization_bank_name' => 'ПАО СБЕРБАНК',
            'payer_bank_name' => 'ООО «Банк Точка»',
            'bank_number' => '9202',
            'uip' => '0',
            'purpose' => 'Оплата по счёту №123',
            'amount' => 2325.20,
            'currency_code' => 'RUB',
        ]);

        $payment = Payment::where('uuid', self::PAYMENT_UUID)->firstOrFail();
        $this->assertTrue($payment->bank_confirmed);
        $this->assertSame('30.07.2026 23:59', $payment->date->format('d.m.Y H:i'));
    }

    #[Test]
    public function it_resolves_company_by_contractor_uuid(): void
    {
        $user = User::factory()->create(['erp_id' => self::PARTNER_UUID]);
        $company = Company::factory()->create([
            'user_id' => $user->id,
            'erp_id' => self::CONTRACTOR_UUID,
            'tax_id' => '7710140679',
        ]);

        (new HandlePaymentCreated)->handle($this->payload(['partner_uuid' => self::PARTNER_UUID]));

        $payment = Payment::where('uuid', self::PAYMENT_UUID)->firstOrFail();
        $this->assertSame($company->id, $payment->company_id);
        $this->assertSame($user->id, $payment->user_id);
    }

    #[Test]
    public function it_falls_back_to_tax_id_only_together_with_partner_uuid(): void
    {
        $user = User::factory()->create(['erp_id' => self::PARTNER_UUID]);
        $company = Company::factory()->create([
            'user_id' => $user->id,
            'erp_id' => null,
            'tax_id' => '7710140679',
        ]);

        (new HandlePaymentCreated)->handle($this->payload([
            'partner_uuid' => self::PARTNER_UUID,
            'tax_id' => '7710140679',
        ]));

        $payment = Payment::where('uuid', self::PAYMENT_UUID)->firstOrFail();
        $this->assertSame($company->id, $payment->company_id);

        // Найденной по ИНН компании доставили erp_id — следующий документ пойдёт быстрым путём.
        $this->assertSame(self::CONTRACTOR_UUID, $company->fresh()->erp_id);
    }

    #[Test]
    public function it_does_not_match_foreign_company_by_tax_id_without_partner_uuid(): void
    {
        $stranger = User::factory()->create(['erp_id' => 'someone-else']);
        Company::factory()->create([
            'user_id' => $stranger->id,
            'erp_id' => null,
            'tax_id' => '7710140679',
        ]);

        (new HandlePaymentCreated)->handle($this->payload(['tax_id' => '7710140679']));

        $payment = Payment::where('uuid', self::PAYMENT_UUID)->firstOrFail();
        $this->assertNull($payment->company_id, 'ИНН без partner_uuid не должен находить чужого контрагента');
        $this->assertNull($payment->user_id);
    }

    #[Test]
    public function it_keeps_contractor_uuid_when_company_is_not_on_site_yet(): void
    {
        (new HandlePaymentCreated)->handle($this->payload());

        $payment = Payment::where('uuid', self::PAYMENT_UUID)->firstOrFail();
        $this->assertNull($payment->company_id);
        $this->assertSame(self::CONTRACTOR_UUID, $payment->contractor_uuid);
    }

    #[Test]
    public function it_syncs_allocations_and_updates_shipment_payment_status(): void
    {
        $shipment = Shipment::factory()->create(['currency_code' => 'RUB', 'total_amount' => 5000.00]);

        (new HandlePaymentCreated)->handle($this->payload([
            'amount' => 2000.00,
            'allocations' => [
                ['shipment_uuid' => $shipment->uuid, 'amount' => 1200.00, 'line_number' => 1],
                ['shipment_uuid' => 'unknown-shipment-uuid', 'amount' => 500.00, 'line_number' => 2],
            ],
        ]));

        $payment = Payment::where('uuid', self::PAYMENT_UUID)->firstOrFail();
        $this->assertCount(2, $payment->allocations);
        $this->assertEquals(1700.00, (float) $payment->allocated_amount);
        $this->assertEquals(300.00, (float) $payment->unallocated_amount);

        // Строка на неизвестную реализацию сохранена без привязки.
        $this->assertDatabaseHas('payment_allocations', [
            'payment_id' => $payment->id,
            'shipment_uuid' => 'unknown-shipment-uuid',
            'shipment_id' => null,
        ]);

        $shipment->refresh();
        $this->assertEquals(1200.00, (float) $shipment->paid_amount);
        $this->assertSame(Shipment::PAYMENT_PARTIAL, $shipment->payment_status);
    }

    #[Test]
    public function it_skips_allocation_rows_without_shipment_uuid_or_with_foreign_target_type(): void
    {
        $shipment = Shipment::factory()->create(['currency_code' => 'RUB', 'total_amount' => 5000.00]);

        (new HandlePaymentCreated)->handle($this->payload([
            'allocations' => [
                ['shipment_uuid' => $shipment->uuid, 'amount' => 100.00],
                ['amount' => 999.00],
                ['shipment_uuid' => $shipment->uuid, 'amount' => 777.00, 'target_type' => 'order'],
            ],
        ]));

        $payment = Payment::where('uuid', self::PAYMENT_UUID)->firstOrFail();
        $this->assertCount(1, $payment->allocations);
        $this->assertEquals(100.00, (float) $payment->allocated_amount);
    }

    #[Test]
    public function it_is_idempotent_on_repeated_delivery(): void
    {
        $shipment = Shipment::factory()->create(['currency_code' => 'RUB', 'total_amount' => 5000.00]);

        $payload = $this->payload([
            'allocations' => [['shipment_uuid' => $shipment->uuid, 'amount' => 1200.00]],
        ]);

        (new HandlePaymentCreated)->handle($payload);
        (new HandlePaymentCreated)->handle($payload);

        $this->assertSame(1, Payment::where('uuid', self::PAYMENT_UUID)->count());

        $payment = Payment::where('uuid', self::PAYMENT_UUID)->firstOrFail();
        $this->assertCount(1, $payment->allocations, 'Повторная доставка не должна задваивать разнесение');
        $this->assertEquals(1200.00, (float) $shipment->fresh()->paid_amount);
    }

    #[Test]
    public function it_restores_soft_deleted_payment(): void
    {
        (new HandlePaymentCreated)->handle($this->payload());
        Payment::where('uuid', self::PAYMENT_UUID)->firstOrFail()->delete();

        (new HandlePaymentCreated)->handle($this->payload(['amount' => 3000.00]));

        $payment = Payment::where('uuid', self::PAYMENT_UUID)->firstOrFail();
        $this->assertNull($payment->deleted_at);
        $this->assertEquals(3000.00, (float) $payment->amount);
    }

    #[Test]
    public function it_treats_missing_erp_timestamps_as_untouched(): void
    {
        (new HandlePaymentCreated)->handle($this->payload([
            'erp_created_at' => '2026-07-30T10:00:00+03:00',
            'erp_updated_at' => '2026-07-30T11:00:00+03:00',
        ]));

        $stored = Payment::where('uuid', self::PAYMENT_UUID)->firstOrFail()->erp_created_at;
        $this->assertNotNull($stored);

        // Ключа нет — сохранённая метка остаётся на месте.
        (new HandlePaymentCreated)->handle($this->payload());

        $payment = Payment::where('uuid', self::PAYMENT_UUID)->firstOrFail();
        $this->assertNotNull($payment->erp_created_at);
        $this->assertSame($stored->timestamp, $payment->erp_created_at->timestamp);
    }

    #[Test]
    public function it_defaults_unknown_direction_to_incoming(): void
    {
        (new HandlePaymentCreated)->handle($this->payload(['direction' => 'что-то новое']));

        $this->assertSame('in', Payment::where('uuid', self::PAYMENT_UUID)->firstOrFail()->direction);
    }
}
