<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\Order;
use App\Models\OrderChangeLog;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Erp\Handlers\HandleOrderUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandleOrderUpdatedTest extends TestCase
{
    use RefreshDatabase;

    private HandleOrderUpdated $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = app(HandleOrderUpdated::class);
    }

    #[Test]
    public function it_does_nothing_when_uuid_missing(): void
    {
        Log::shouldReceive('warning')->once();

        $this->handler->handle([]);

        $this->assertDatabaseCount('order_change_logs', 0);
    }

    #[Test]
    public function it_does_nothing_when_order_not_found(): void
    {
        Log::shouldReceive('info')->once();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->handler->handle(['uuid' => 'non-existing-uuid']);

        $this->assertDatabaseCount('order_change_logs', 0);
    }

    #[Test]
    public function it_updates_order_status(): void
    {
        $order = Order::factory()->create([
            'uuid' => 'test-uuid-status',
            'status' => 'pending',
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->handler->handle([
            'uuid' => 'test-uuid-status',
            'status' => 'confirmed',
        ]);

        $this->assertEquals('confirmed', $order->fresh()->status->value);
    }

    #[Test]
    public function it_syncs_items_when_items_provided(): void
    {
        $order = Order::factory()->create(['uuid' => 'test-uuid-sync']);
        $product = Product::factory()->create(['external_id' => 'prod-uuid-1']);

        // Существующая позиция
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => 'Старый товар',
            'price' => 100,
            'base_price' => 100,
            'final_price' => 100,
            'discount_percent' => 0,
            'quantity' => 2,
            'subtotal' => 200,
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->handler->handle([
            'uuid' => 'test-uuid-sync',
            'items' => [
                [
                    'product_uuid' => 'prod-uuid-1',
                    'quantity' => 5,
                    'base_price' => 100,
                    'final_price' => 100,
                    'discount_percent' => 0,
                ],
            ],
        ]);

        $order->refresh();
        $this->assertCount(1, $order->items);
        $this->assertEquals(5, $order->items->first()->quantity);
    }

    #[Test]
    public function it_does_not_touch_items_when_items_absent(): void
    {
        $order = Order::factory()->create(['uuid' => 'test-uuid-no-items']);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'quantity' => 3,
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->handler->handle([
            'uuid' => 'test-uuid-no-items',
            'status' => 'confirmed',
        ]);

        $this->assertCount(1, $order->fresh()->items);
        $this->assertEquals(3, $order->fresh()->items->first()->quantity);
    }

    #[Test]
    public function it_logs_item_added(): void
    {
        $order = Order::factory()->create([
            'uuid' => 'test-uuid-add',
            'total_amount' => 0,
        ]);
        $product = Product::factory()->create([
            'external_id' => 'prod-new-1',
            'name' => 'Новый товар',
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->handler->handle([
            'uuid' => 'test-uuid-add',
            'items' => [
                [
                    'product_uuid' => 'prod-new-1',
                    'quantity' => 3,
                    'base_price' => 500,
                    'final_price' => 500,
                    'discount_percent' => 0,
                ],
            ],
        ]);

        $log = OrderChangeLog::where('order_id', $order->id)->first();
        $this->assertNotNull($log);
        $this->assertEquals('items_updated', $log->type);

        $changes = $log->changes;
        $this->assertCount(1, $changes['added']);
        $this->assertEquals('Новый товар', $changes['added'][0]['product_name']);
    }

    #[Test]
    public function it_logs_item_removed(): void
    {
        $order = Order::factory()->create([
            'uuid' => 'test-uuid-remove',
            'total_amount' => 1000,
        ]);
        $product = Product::factory()->create([
            'external_id' => 'prod-rm-1',
            'name' => 'Удаляемый товар',
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => 'Удаляемый товар',
            'price' => 500,
            'base_price' => 500,
            'final_price' => 500,
            'discount_percent' => 0,
            'quantity' => 2,
            'subtotal' => 1000,
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        // Отправляем пустые items — товар удалён
        $this->handler->handle([
            'uuid' => 'test-uuid-remove',
            'items' => [],
        ]);

        $log = OrderChangeLog::where('order_id', $order->id)->first();
        $this->assertNotNull($log);
        $this->assertCount(1, $log->changes['removed']);
        $this->assertEquals('Удаляемый товар', $log->changes['removed'][0]['product_name']);
    }

    #[Test]
    public function it_logs_item_modified(): void
    {
        $order = Order::factory()->create([
            'uuid' => 'test-uuid-modify',
            'total_amount' => 1000,
        ]);
        $product = Product::factory()->create([
            'external_id' => 'prod-mod-1',
            'name' => 'Изменяемый товар',
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => 'Изменяемый товар',
            'price' => 500,
            'base_price' => 500,
            'final_price' => 500,
            'discount_percent' => 0,
            'quantity' => 5,
            'subtotal' => 2500,
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->handler->handle([
            'uuid' => 'test-uuid-modify',
            'items' => [
                [
                    'product_uuid' => 'prod-mod-1',
                    'quantity' => 3,
                    'base_price' => 500,
                    'final_price' => 400,
                    'discount_percent' => 20,
                ],
            ],
        ]);

        $log = OrderChangeLog::where('order_id', $order->id)->first();
        $this->assertNotNull($log);
        $this->assertCount(1, $log->changes['modified']);

        $mod = $log->changes['modified'][0];
        $this->assertEquals(5, $mod['changes']['quantity']['old']);
        $this->assertEquals(3, $mod['changes']['quantity']['new']);
        $this->assertEquals(500, $mod['changes']['final_price']['old']);
        $this->assertEquals(400, $mod['changes']['final_price']['new']);
    }

    #[Test]
    public function it_records_old_and_new_total(): void
    {
        $order = Order::factory()->create([
            'uuid' => 'test-uuid-totals',
            'total_amount' => 2500,
        ]);
        $product = Product::factory()->create([
            'external_id' => 'prod-total-1',
            'name' => 'Товар с итогом',
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => 'Товар с итогом',
            'price' => 500,
            'base_price' => 500,
            'final_price' => 500,
            'discount_percent' => 0,
            'quantity' => 5,
            'subtotal' => 2500,
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->handler->handle([
            'uuid' => 'test-uuid-totals',
            'items' => [
                [
                    'product_uuid' => 'prod-total-1',
                    'quantity' => 3,
                    'base_price' => 500,
                    'final_price' => 500,
                    'discount_percent' => 0,
                ],
            ],
        ]);

        $log = OrderChangeLog::where('order_id', $order->id)->first();
        $this->assertNotNull($log);
        $this->assertEquals(2500, $log->old_total);
        $this->assertEquals(1500, $log->new_total);
    }

    #[Test]
    public function it_generates_russian_summary(): void
    {
        $order = Order::factory()->create([
            'uuid' => 'test-uuid-summary',
            'total_amount' => 500,
        ]);
        $product = Product::factory()->create([
            'external_id' => 'prod-sum-1',
            'name' => 'Помада Rouge',
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => 'Помада Rouge',
            'price' => 500,
            'base_price' => 500,
            'final_price' => 500,
            'discount_percent' => 0,
            'quantity' => 1,
            'subtotal' => 500,
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->handler->handle([
            'uuid' => 'test-uuid-summary',
            'items' => [
                [
                    'product_uuid' => 'prod-sum-1',
                    'quantity' => 3,
                    'base_price' => 500,
                    'final_price' => 500,
                    'discount_percent' => 0,
                ],
            ],
        ]);

        $log = OrderChangeLog::where('order_id', $order->id)->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Помада Rouge', $log->summary);
        $this->assertStringContainsString('кол-во', $log->summary);
        $this->assertStringContainsString('→', $log->summary);
    }

    #[Test]
    public function it_does_not_log_when_items_unchanged(): void
    {
        $order = Order::factory()->create([
            'uuid' => 'test-uuid-nochange',
            'total_amount' => 1000,
        ]);
        $product = Product::factory()->create([
            'external_id' => 'prod-nc-1',
            'name' => 'Без изменений',
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => 'Без изменений',
            'price' => 500,
            'base_price' => 500,
            'final_price' => 500,
            'discount_percent' => 0,
            'quantity' => 2,
            'subtotal' => 1000,
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->handler->handle([
            'uuid' => 'test-uuid-nochange',
            'items' => [
                [
                    'product_uuid' => 'prod-nc-1',
                    'quantity' => 2,
                    'base_price' => 500,
                    'final_price' => 500,
                    'discount_percent' => 0,
                ],
            ],
        ]);

        $this->assertDatabaseCount('order_change_logs', 0);
    }

    #[Test]
    public function it_saves_erp_number_from_payload(): void
    {
        $order = Order::factory()->create([
            'uuid' => 'test-uuid-erp-num',
            'erp_number' => null,
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();

        $this->handler->handle([
            'uuid' => 'test-uuid-erp-num',
            'number' => 'ЗКП-000123',
        ]);

        $this->assertEquals('ЗКП-000123', $order->fresh()->erp_number);
    }

    #[Test]
    public function it_updates_erp_number_on_redelivery(): void
    {
        $order = Order::factory()->create([
            'uuid' => 'test-uuid-erp-upd',
            'erp_number' => 'ЗКП-000100',
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();

        $this->handler->handle([
            'uuid' => 'test-uuid-erp-upd',
            'number' => 'ЗКП-000200',
        ]);

        $this->assertEquals('ЗКП-000200', $order->fresh()->erp_number);
    }

    #[Test]
    public function it_updates_order_status_to_deleted(): void
    {
        $order = Order::factory()->create([
            'uuid' => 'test-uuid-status-deleted',
            'status' => 'confirmed',
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->handler->handle([
            'uuid' => 'test-uuid-status-deleted',
            'status' => 'deleted',
        ]);

        $this->assertEquals('deleted', $order->fresh()->status->value);
    }

    #[Test]
    public function it_updates_erp_updated_at_when_present_v13_7(): void
    {
        $order = Order::factory()->create([
            'uuid' => 'test-uuid-erp-upd-ts',
            'erp_created_at' => '2026-04-26 10:15:32',
            'erp_updated_at' => '2026-04-26 10:15:32',
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->handler->handle([
            'uuid' => 'test-uuid-erp-upd-ts',
            'status' => 'confirmed',
            'erp_updated_at' => '2026-04-26T14:42:09+03:00',
        ]);

        $fresh = $order->fresh();
        $this->assertEquals('2026-04-26 14:42:09', $fresh->erp_updated_at->format('Y-m-d H:i:s'));
        // erp_created_at не должен быть затронут
        $this->assertEquals('2026-04-26 10:15:32', $fresh->erp_created_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_keeps_existing_erp_timestamps_when_absent_from_payload_v13_7(): void
    {
        $order = Order::factory()->create([
            'uuid' => 'test-uuid-erp-keep-ts',
            'erp_created_at' => '2026-04-26 10:15:32',
            'erp_updated_at' => '2026-04-26 10:15:32',
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->handler->handle([
            'uuid' => 'test-uuid-erp-keep-ts',
            'status' => 'confirmed',
        ]);

        $fresh = $order->fresh();
        $this->assertEquals('2026-04-26 10:15:32', $fresh->erp_created_at->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-04-26 10:15:32', $fresh->erp_updated_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_accepts_negative_discount_percent_as_markup_and_logs_neutral_summary(): void
    {
        $order = Order::factory()->create([
            'uuid' => 'test-uuid-negative-discount',
            'total_amount' => 1000,
        ]);
        $product = Product::factory()->create([
            'external_id' => 'prod-negative-1',
            'name' => 'Товар с наценкой',
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => 'Товар с наценкой',
            'price' => 1000,
            'base_price' => 1000,
            'final_price' => 1000,
            'discount_percent' => 0,
            'quantity' => 1,
            'subtotal' => 1000,
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->handler->handle([
            'uuid' => 'test-uuid-negative-discount',
            'items' => [
                [
                    'product_uuid' => 'prod-negative-1',
                    'quantity' => 1,
                    'base_price' => 1000,
                    'final_price' => 1150,
                    'discount_percent' => -15,
                ],
            ],
        ]);

        $order->refresh();
        $item = $order->items->first();
        $log = OrderChangeLog::where('order_id', $order->id)->first();

        $this->assertEquals(-15.00, (float) $item->discount_percent);
        $this->assertEquals(1150.00, (float) $item->final_price);
        $this->assertEquals(1150.00, (float) $order->total_amount);
        $this->assertNotNull($log);
        $this->assertStringContainsString('корректировка цены: 0% → -15%', $log->summary);
    }

    #[Test]
    public function it_does_not_overwrite_user_comment_from_payload(): void
    {
        // v13.8: comment — пользовательское поле, шина 1С его не трогает,
        // даже если payload содержит явное значение.
        $order = Order::factory()->create([
            'uuid' => 'test-uuid-comment-protected',
            'status' => 'pending',
            'comment' => 'Доставить до 12:00, не звонить — оставить у консьержа',
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->handler->handle([
            'uuid' => 'test-uuid-comment-protected',
            'status' => 'confirmed',
            'comment' => 'Менеджер 1С: попытка переписать клиентский комментарий',
        ]);

        $order->refresh();
        $this->assertEquals('Доставить до 12:00, не звонить — оставить у консьержа', $order->comment);
        // Статус всё-таки обновляется — проверяем, что обработчик отрабатывает остальные поля
        $this->assertEquals('confirmed', $order->status->value);
    }
}
