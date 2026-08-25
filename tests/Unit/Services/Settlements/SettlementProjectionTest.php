<?php

namespace Tests\Unit\Services\Settlements;

use App\Models\Order;
use App\Models\SettlementEntry;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Settlements\SettlementProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Проекция колонок оплаты из плановых строк регистра (fin-11, волна 3).
 *
 * Колонки `shipments.paid_amount / payment_status / payment_due_date`
 * и `orders.prepaid_amount` читают кабинет, внешний API и карточки трёх
 * панелей — проекция обязана давать те же числа, что и раздел «Финансы».
 */
class SettlementProjectionTest extends TestCase
{
    use RefreshDatabase;

    private function projector(): SettlementProjector
    {
        return app(SettlementProjector::class);
    }

    private function shipment(array $attributes = []): Shipment
    {
        return Shipment::factory()->create($attributes + [
            'total_amount' => 10000,
            'paid_amount' => 0,
            'payment_status' => Shipment::PAYMENT_UNPAID,
        ]);
    }

    private function plan(string $documentUuid, array $attributes = []): SettlementEntry
    {
        return SettlementEntry::factory()->create($attributes + [
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'document_uuid' => $documentUuid,
            'document_kind' => 'shipment',
            'amount' => 10000,
            'settled_amount' => 0,
        ]);
    }

    #[Test]
    public function частично_закрытый_план_даёт_partial_и_ближайшую_дату(): void
    {
        $shipment = $this->shipment();

        $this->plan($shipment->uuid, ['amount' => 6000, 'settled_amount' => 6000, 'date' => '2026-08-01']);
        $this->plan($shipment->uuid, ['amount' => 4000, 'settled_amount' => 1000, 'date' => '2026-09-10']);

        $this->projector()->projectShipment($shipment);
        $shipment->refresh();

        $this->assertEqualsWithDelta(7000.0, (float) $shipment->paid_amount, 0.01);
        $this->assertSame(Shipment::PAYMENT_PARTIAL, $shipment->payment_status);
        // Ближайшая непогашенная строка, а не первая по номеру.
        $this->assertSame('2026-09-10', $shipment->payment_due_date?->toDateString());
    }

    #[Test]
    public function закрытый_план_даёт_paid_и_снимает_срок(): void
    {
        $shipment = $this->shipment(['payment_due_date' => '2026-08-01']);
        $this->plan($shipment->uuid, ['amount' => 10000, 'settled_amount' => 10000, 'date' => '2026-08-01']);

        $this->projector()->projectShipment($shipment);
        $shipment->refresh();

        $this->assertSame(Shipment::PAYMENT_PAID, $shipment->payment_status);
        $this->assertNull($shipment->payment_due_date);
    }

    #[Test]
    public function переплата_строки_не_гасит_долг_другой_и_даёт_overpaid_только_по_итогу(): void
    {
        $shipment = $this->shipment();

        // 7000 при плане 5000 — переплата; вторая строка не закрыта.
        $this->plan($shipment->uuid, ['amount' => 5000, 'settled_amount' => 7000, 'date' => '2026-08-01']);
        $second = $this->plan($shipment->uuid, ['amount' => 5000, 'settled_amount' => 0, 'date' => '2026-08-15']);

        $this->projector()->projectShipment($shipment);
        $shipment->refresh();

        // Оплачено 5000 (кламп), а не 7000: переплата одной строки не деньги другой.
        $this->assertEqualsWithDelta(5000.0, (float) $shipment->paid_amount, 0.01);
        $this->assertSame(Shipment::PAYMENT_PARTIAL, $shipment->payment_status);

        // Теперь закрыта и вторая, с общей переплатой — overpaid.
        $second->update(['settled_amount' => 5000]);

        $this->projector()->projectShipment($shipment);

        $this->assertSame(Shipment::PAYMENT_OVERPAID, $shipment->refresh()->payment_status);
    }

    #[Test]
    public function нулевая_сумма_документа_означает_оплачен(): void
    {
        $shipment = $this->shipment(['total_amount' => 0]);
        $this->plan($shipment->uuid, ['amount' => 0, 'settled_amount' => 0, 'date' => '2026-08-01']);

        $this->projector()->projectShipment($shipment);

        $this->assertSame(Shipment::PAYMENT_PAID, $shipment->refresh()->payment_status);
    }

    /**
     * Реализация без плановых строк: у регистра нет мнения, значения прежнего
     * писателя не затираются — обнуление показало бы «не оплачена» там,
     * где старые данные хоть что-то отражали.
     */
    #[Test]
    public function документ_без_плана_не_трогается(): void
    {
        $shipment = $this->shipment([
            'paid_amount' => 4200,
            'payment_status' => Shipment::PAYMENT_PARTIAL,
        ]);

        $this->projector()->projectShipment($shipment);
        $shipment->refresh();

        $this->assertEqualsWithDelta(4200.0, (float) $shipment->paid_amount, 0.01);
        $this->assertSame(Shipment::PAYMENT_PARTIAL, $shipment->payment_status);
    }

    #[Test]
    public function проекция_идемпотентна(): void
    {
        $shipment = $this->shipment();
        $this->plan($shipment->uuid, ['amount' => 10000, 'settled_amount' => 3000, 'date' => '2026-08-20']);

        $this->projector()->projectShipment($shipment);
        $first = $shipment->refresh()->updated_at;

        $this->travel(1)->minutes();
        $this->projector()->projectShipment($shipment);

        // Без изменений — без UPDATE: повторный вызов не трогает updated_at.
        $this->assertTrue($shipment->refresh()->updated_at->equalTo($first));
    }

    #[Test]
    public function предоплата_заказа_берётся_из_document_settled_amount(): void
    {
        $order = Order::factory()->create(['prepaid_amount' => 0]);

        // Авторитетная сумма на документ важнее построчного деления.
        SettlementEntry::factory()->create([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'document_uuid' => $order->uuid,
            'document_kind' => 'order',
            'amount' => 8000,
            'settled_amount' => 2000,
            'document_settled_amount' => 5000,
            'is_settled_derived' => true,
        ]);

        $this->projector()->projectOrder($order);

        $this->assertEqualsWithDelta(5000.0, (float) $order->refresh()->prepaid_amount, 0.01);
    }

    #[Test]
    public function project_document_различает_виды_документов(): void
    {
        $user = User::factory()->create();
        $shipment = $this->shipment(['user_id' => $user->id]);
        $this->plan($shipment->uuid, ['amount' => 10000, 'settled_amount' => 10000, 'date' => '2026-08-01']);

        $this->projector()->projectDocument($shipment->uuid);

        $this->assertSame(Shipment::PAYMENT_PAID, $shipment->refresh()->payment_status);
    }
}
