<?php

namespace Tests\Unit\Support;

use App\Models\SettlementEntry;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Payments\PaymentSchedulePresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Блок «График оплаты» — один презентер на кабинет, CRM, админку и внешний API.
 *
 * Источник — плановые строки регистра (fin-11). Главное требование прежнее:
 * **шапка обязана сходиться со строками под ней**. Разошедшиеся числа в одном
 * блоке подрывают доверие ко всему разделу сильнее, чем отсутствие блока.
 */
class PaymentSchedulePresenterTest extends TestCase
{
    use RefreshDatabase;

    private Shipment $shipment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shipment = Shipment::factory()->create([
            'user_id' => User::factory()->create()->id,
            'total_amount' => 100000,
        ]);
    }

    /**
     * Регистр не делит закрытую часть на «разнесено» и «зачтено авансом»:
     * 1С отдаёт одно число, поэтому оба слагаемых складываются.
     */
    private function line(float $amount, float $paid = 0, float $prepaid = 0): void
    {
        SettlementEntry::factory()->create([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'user_id' => $this->shipment->user_id,
            'document_uuid' => $this->shipment->uuid,
            'document_kind' => 'shipment',
            'date' => now()->addDays(10)->toDateString(),
            'amount' => $amount,
            'settled_amount' => $paid + $prepaid,
            'currency_code' => 'RUB',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(): array
    {
        return PaymentSchedulePresenter::forShipment($this->shipment->fresh());
    }

    /**
     * Аванс по заказу закрывает строку наравне с прямым разнесением — так считает
     * `unpaid_amount` у строки. Шапка обязана считать так же.
     */
    #[Test]
    public function аванс_по_заказу_учитывается_в_шапке(): void
    {
        $this->line(60000, paid: 0, prepaid: 60000);
        $this->line(40000, paid: 10000);

        $schedule = $this->present();

        $this->assertEqualsWithDelta(70000.0, $schedule['paid_amount'], 0.01);
        $this->assertEqualsWithDelta(30000.0, $schedule['unpaid_amount'], 0.01);
    }

    #[Test]
    public function итог_шапки_равен_сумме_строк(): void
    {
        $this->line(60000, prepaid: 60000);
        $this->line(40000, paid: 10000);

        $schedule = $this->present();

        $this->assertEqualsWithDelta(
            array_sum(array_column($schedule['lines'], 'unpaid_amount')),
            $schedule['unpaid_amount'],
            0.01,
        );
    }

    /**
     * Переплата по одной строке не должна гасить долг по другой: остаток строки
     * клампится в ноль, и итог обязан складываться из клампленных значений.
     */
    #[Test]
    public function переплата_одной_строки_не_гасит_долг_другой(): void
    {
        $this->line(30000, paid: 50000);
        $this->line(70000);

        $schedule = $this->present();

        $this->assertEqualsWithDelta(70000.0, $schedule['unpaid_amount'], 0.01);
    }

    #[Test]
    public function пересчёт_в_валюту_показа_применяется_к_итогам(): void
    {
        $this->line(100000, paid: 25000);

        $schedule = PaymentSchedulePresenter::forShipment(
            $this->shipment->fresh(),
            static fn (float $amount): float => round($amount / 100, 2),
        );

        $this->assertEqualsWithDelta(1000.0, $schedule['total_amount'], 0.01);
        $this->assertEqualsWithDelta(250.0, $schedule['paid_amount'], 0.01);
        $this->assertEqualsWithDelta(750.0, $schedule['unpaid_amount'], 0.01);
    }

    /**
     * Арифметику документа ведёт 1С: расхождение показываем, но не «чиним».
     */
    #[Test]
    public function расхождение_с_суммой_документа_помечается(): void
    {
        $this->line(80000);

        $this->assertTrue($this->present()['mismatches_document']);
    }

    #[Test]
    public function график_без_строк_не_показывается(): void
    {
        $this->assertNull(PaymentSchedulePresenter::forShipment($this->shipment));
    }
}
