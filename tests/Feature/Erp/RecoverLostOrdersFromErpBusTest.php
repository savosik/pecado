<?php

namespace Tests\Feature\Erp;

use App\Jobs\PublishOrderToErpJob;
use App\Models\ErpBusMessage;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * v15.4: команда erp:recover-lost-orders — восстановление заказов, потерянных
 * из-за неприсланного 1С order.created.
 */
class RecoverLostOrdersFromErpBusTest extends TestCase
{
    use RefreshDatabase;

    private function logUpdate(array $payload, string $messageId): void
    {
        ErpBusMessage::create([
            'direction' => 'incoming',
            'routing_key' => 'erp_in.orders',
            'event' => 'order.updated',
            'message_id' => $messageId,
            'payload' => $payload,
            'status' => 'success',
        ]);
    }

    private function lostOrderPayload(array $overrides = []): array
    {
        return array_merge([
            'event' => 'order.updated',
            'uuid' => 'lost-uuid-1',
            'number' => '29УТ-010318',
            'status' => 'ready_for_shipment',
            'partner_uuid' => 'partner-1',
            'contractor' => ['uuid' => 'contractor-1', 'tax_id' => '780528446072'],
            'items' => [
                ['product_uuid' => 'product-1', 'quantity' => 2, 'base_price' => 100, 'final_price' => 80],
            ],
        ], $overrides);
    }

    #[Test]
    public function dry_run_reports_lost_orders_without_changing_anything(): void
    {
        User::factory()->create(['erp_id' => 'partner-1']);
        Product::factory()->create(['external_id' => 'product-1']);

        $this->logUpdate($this->lostOrderPayload(), 'msg-1');

        $this->artisan('erp:recover-lost-orders', ['--dry-run' => true])
            ->expectsOutputToContain('29УТ-010318')
            ->expectsOutputToContain('Пробный прогон')
            ->assertSuccessful();

        $this->assertDatabaseMissing('orders', ['uuid' => 'lost-uuid-1']);
    }

    #[Test]
    public function it_recovers_lost_order_from_bus_log(): void
    {
        $user = User::factory()->create(['erp_id' => 'partner-1']);
        $product = Product::factory()->create(['external_id' => 'product-1']);

        $this->logUpdate($this->lostOrderPayload(), 'msg-1');

        $this->artisan('erp:recover-lost-orders')
            ->expectsConfirmation('Восстановить 1 заказ(ов)?', 'yes')
            ->assertSuccessful();

        $order = Order::where('uuid', 'lost-uuid-1')->first();

        $this->assertNotNull($order);
        $this->assertSame('29УТ-010318', $order->erp_number);
        $this->assertSame($user->id, $order->user_id);
        $this->assertEqualsWithDelta(160.0, (float) $order->total_amount, 0.01);
        $this->assertSame($product->id, $order->items()->first()->product_id);
    }

    /**
     * Из нескольких обновлений берётся последнее — у заказа должен оказаться
     * актуальный статус, а не тот, что был в первом сообщении.
     */
    #[Test]
    public function it_uses_latest_update_payload(): void
    {
        User::factory()->create(['erp_id' => 'partner-1']);
        Product::factory()->create(['external_id' => 'product-1']);

        $this->logUpdate($this->lostOrderPayload(['status' => 'pending_approval']), 'msg-1');
        $this->logUpdate($this->lostOrderPayload(['status' => 'closed']), 'msg-2');

        $this->artisan('erp:recover-lost-orders')
            ->expectsConfirmation('Восстановить 1 заказ(ов)?', 'yes')
            ->assertSuccessful();

        $order = Order::where('uuid', 'lost-uuid-1')->first();
        $this->assertSame('closed', $order->status->value ?? $order->status);
    }

    #[Test]
    public function it_skips_orders_that_already_exist(): void
    {
        User::factory()->create(['erp_id' => 'partner-1']);
        Order::factory()->create(['uuid' => 'lost-uuid-1']);

        $this->logUpdate($this->lostOrderPayload(), 'msg-1');

        $this->artisan('erp:recover-lost-orders', ['--dry-run' => true])
            ->expectsOutputToContain('Потерянных заказов не найдено')
            ->assertSuccessful();
    }

    /**
     * Заказ без позиций — восстанавливать нечем, создавать пустой заказ нельзя.
     */
    #[Test]
    public function it_reports_unrecoverable_orders_without_creating_them(): void
    {
        $this->logUpdate($this->lostOrderPayload([
            'uuid' => 'lost-uuid-empty',
            'number' => '29УТ-009892',
            'items' => [],
        ]), 'msg-empty');

        $this->artisan('erp:recover-lost-orders', ['--dry-run' => true])
            ->expectsOutputToContain('без данных для восстановления')
            ->expectsOutputToContain('29УТ-009892')
            ->assertSuccessful();

        $this->assertDatabaseMissing('orders', ['uuid' => 'lost-uuid-empty']);
    }

    #[Test]
    public function numbers_option_limits_scope(): void
    {
        User::factory()->create(['erp_id' => 'partner-1']);
        Product::factory()->create(['external_id' => 'product-1']);

        $this->logUpdate($this->lostOrderPayload(), 'msg-1');
        $this->logUpdate($this->lostOrderPayload([
            'uuid' => 'lost-uuid-2',
            'number' => '29УТ-010319',
        ]), 'msg-2');

        $this->artisan('erp:recover-lost-orders', ['--numbers' => '29УТ-010319'])
            ->expectsConfirmation('Восстановить 1 заказ(ов)?', 'yes')
            ->assertSuccessful();

        $this->assertDatabaseHas('orders', ['uuid' => 'lost-uuid-2']);
        $this->assertDatabaseMissing('orders', ['uuid' => 'lost-uuid-1']);
    }

    #[Test]
    public function declining_confirmation_changes_nothing(): void
    {
        User::factory()->create(['erp_id' => 'partner-1']);
        Product::factory()->create(['external_id' => 'product-1']);

        $this->logUpdate($this->lostOrderPayload(), 'msg-1');

        $this->artisan('erp:recover-lost-orders')
            ->expectsConfirmation('Восстановить 1 заказ(ов)?', 'no')
            ->expectsOutputToContain('Отменено')
            ->assertSuccessful();

        $this->assertDatabaseMissing('orders', ['uuid' => 'lost-uuid-1']);
    }

    /**
     * Восстановленный заказ не должен уехать обратно в 1С — иначе получим
     * дубль документа на стороне ERP.
     */
    #[Test]
    public function recovered_order_is_not_published_back_to_erp(): void
    {
        User::factory()->create(['erp_id' => 'partner-1']);
        Product::factory()->create(['external_id' => 'product-1']);

        $this->logUpdate($this->lostOrderPayload(), 'msg-1');

        // fake после фикстур: создание пользователя само по себе публикует
        // PublishUserToErpJob, и он бы зашумил проверку.
        Queue::fake();

        $this->artisan('erp:recover-lost-orders')
            ->expectsConfirmation('Восстановить 1 заказ(ов)?', 'yes')
            ->assertSuccessful();

        Queue::assertNotPushed(PublishOrderToErpJob::class);
    }
}
