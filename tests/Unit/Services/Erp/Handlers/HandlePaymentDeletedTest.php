<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\Payment;
use App\Services\Erp\Handlers\HandlePaymentCreated;
use App\Services\Erp\Handlers\HandlePaymentDeleted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Удаление платежа — мягкое: платёж помечается удалённым и исчезает из журнала.
 *
 * Оплату реализаций удаление не трогает (fin-11): погашения считает регистр,
 * и отмена проведения приходит своим событием `settlement.reverted`.
 */
class HandlePaymentDeletedTest extends TestCase
{
    use RefreshDatabase;

    private const PAYMENT_UUID = 'p1a2b3c4-d5e6-7890-abcd-ef1234567890';

    private function createPayment(): Payment
    {
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

        return Payment::where('uuid', self::PAYMENT_UUID)->firstOrFail();
    }

    #[Test]
    public function it_soft_deletes_payment(): void
    {
        $this->createPayment();

        (new HandlePaymentDeleted)->handle(['event' => 'payment.deleted', 'uuid' => self::PAYMENT_UUID]);

        $this->assertSoftDeleted('payments', ['uuid' => self::PAYMENT_UUID]);
    }

    #[Test]
    public function reposting_restores_soft_deleted_payment(): void
    {
        $this->createPayment();

        (new HandlePaymentDeleted)->handle(['event' => 'payment.deleted', 'uuid' => self::PAYMENT_UUID]);

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
    }

    #[Test]
    public function it_is_idempotent_and_tolerates_unknown_uuid(): void
    {
        $this->createPayment();

        (new HandlePaymentDeleted)->handle(['event' => 'payment.deleted', 'uuid' => self::PAYMENT_UUID]);
        (new HandlePaymentDeleted)->handle(['event' => 'payment.deleted', 'uuid' => self::PAYMENT_UUID]);
        (new HandlePaymentDeleted)->handle(['event' => 'payment.deleted', 'uuid' => 'never-existed']);

        $this->assertSoftDeleted('payments', ['uuid' => self::PAYMENT_UUID]);
    }
}
