<?php

namespace Tests\Feature\Console;

use App\Models\Order;
use App\Models\SettlementEntry;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Бэкфил проекций оплаты из регистра — `settlements:project-documents`.
 */
class ProjectSettlementDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private function plan(string $documentUuid, string $kind, array $attributes = []): SettlementEntry
    {
        return SettlementEntry::factory()->create($attributes + [
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'document_uuid' => $documentUuid,
            'document_kind' => $kind,
            'amount' => 10000,
            'settled_amount' => 10000,
            'date' => '2026-08-01',
        ]);
    }

    #[Test]
    public function команда_проецирует_реализации_и_заказы(): void
    {
        $shipment = Shipment::factory()->create([
            'total_amount' => 10000,
            'paid_amount' => 0,
            'payment_status' => Shipment::PAYMENT_UNPAID,
        ]);
        $order = Order::factory()->create(['prepaid_amount' => 0]);

        $this->plan($shipment->uuid, 'shipment');
        $this->plan($order->uuid, 'order', ['settled_amount' => 3000, 'document_settled_amount' => 3000]);

        $this->artisan('settlements:project-documents')->assertSuccessful();

        $this->assertSame(Shipment::PAYMENT_PAID, $shipment->refresh()->payment_status);
        $this->assertEqualsWithDelta(3000.0, (float) $order->refresh()->prepaid_amount, 0.01);
    }

    #[Test]
    public function dry_run_ничего_не_пишет(): void
    {
        $shipment = Shipment::factory()->create([
            'total_amount' => 10000,
            'paid_amount' => 0,
            'payment_status' => Shipment::PAYMENT_UNPAID,
        ]);
        $this->plan($shipment->uuid, 'shipment');

        $this->artisan('settlements:project-documents', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(Shipment::PAYMENT_UNPAID, $shipment->refresh()->payment_status);
    }

    #[Test]
    public function точечная_репроекция_по_uuid(): void
    {
        $target = Shipment::factory()->create(['total_amount' => 10000, 'payment_status' => Shipment::PAYMENT_UNPAID]);
        $other = Shipment::factory()->create(['total_amount' => 10000, 'payment_status' => Shipment::PAYMENT_UNPAID]);

        $this->plan($target->uuid, 'shipment');
        $this->plan($other->uuid, 'shipment');

        $this->artisan('settlements:project-documents', ['--uuid' => [$target->uuid]])->assertSuccessful();

        $this->assertSame(Shipment::PAYMENT_PAID, $target->refresh()->payment_status);
        $this->assertSame(Shipment::PAYMENT_UNPAID, $other->refresh()->payment_status);
    }

    /**
     * Реализации с оплатой от снесённого писателя, но без плана в регистре,
     * не затираются — команда о них предупреждает.
     */
    #[Test]
    public function документы_без_плана_не_затираются_и_попадают_в_отчёт(): void
    {
        $orphan = Shipment::factory()->create([
            'total_amount' => 10000,
            'paid_amount' => 4200,
            'payment_status' => Shipment::PAYMENT_PARTIAL,
        ]);

        $this->artisan('settlements:project-documents')
            ->expectsOutputToContain('без плана в регистре: 1')
            ->assertSuccessful();

        $this->assertEqualsWithDelta(4200.0, (float) $orphan->refresh()->paid_amount, 0.01);
    }
}
