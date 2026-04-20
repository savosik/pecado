<?php

namespace Tests\Unit\Services\Order;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Order\OrderChangeLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderChangeLoggerTest extends TestCase
{
    use RefreshDatabase;

    private OrderChangeLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new OrderChangeLogger;
    }

    #[Test]
    public function snapshot_attributes_includes_labels_for_relations(): void
    {
        $user = User::factory()->create(['name' => 'Клиент X']);
        $company = Company::factory()->create(['name' => 'ООО Ромашка', 'user_id' => $user->id]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'delivery_address' => 'Адрес 1',
            'comment' => 'комм',
            'total_amount' => 100,
        ]);
        $order->load(['user', 'company']);

        $snap = $this->logger->snapshotAttributes($order);

        $this->assertSame('Клиент X', $snap['user_name']);
        $this->assertSame('ООО Ромашка', $snap['company_name']);
        $this->assertSame('Адрес 1', $snap['delivery_address']);
    }

    #[Test]
    public function it_logs_attribute_changes_with_source_and_user(): void
    {
        $actor = User::factory()->create(['name' => 'Администратор']);
        $order = Order::factory()->create([
            'delivery_address' => 'Старый адрес',
            'comment' => null,
            'total_amount' => 100,
        ]);
        $order->load(['user', 'company']);
        $old = $this->logger->snapshotAttributes($order);

        $order->update([
            'delivery_address' => 'Новый адрес',
            'comment' => 'Срочная доставка',
        ]);
        $order->refresh()->load(['user', 'company']);
        $new = $this->logger->snapshotAttributes($order);

        $log = $this->logger->logAttributeChanges($order, $old, $new, 'admin', $actor->id);

        $this->assertNotNull($log);
        $this->assertSame('attributes_updated', $log->type);
        $this->assertSame('admin', $log->source);
        $this->assertSame($actor->id, $log->user_id);

        $diff = $log->changes['attributes'];
        $this->assertArrayHasKey('delivery_address', $diff);
        $this->assertSame('Старый адрес', $diff['delivery_address']['old']);
        $this->assertSame('Новый адрес', $diff['delivery_address']['new']);
        $this->assertArrayHasKey('comment', $diff);

        $this->assertStringContainsString('Адрес доставки', $log->summary);
        $this->assertStringContainsString('→', $log->summary);
    }

    #[Test]
    public function it_does_not_log_when_attributes_unchanged(): void
    {
        $order = Order::factory()->create(['delivery_address' => 'Адрес', 'comment' => 'X']);
        $order->load(['user', 'company']);
        $old = $this->logger->snapshotAttributes($order);
        $new = $this->logger->snapshotAttributes($order);

        $log = $this->logger->logAttributeChanges($order, $old, $new, 'admin');

        $this->assertNull($log);
        $this->assertDatabaseCount('order_change_logs', 0);
    }

    #[Test]
    public function it_logs_company_change_with_labels(): void
    {
        $c1 = Company::factory()->create(['name' => 'A']);
        $c2 = Company::factory()->create(['name' => 'B']);
        $order = Order::factory()->create(['company_id' => $c1->id]);
        $order->load(['user', 'company']);
        $old = $this->logger->snapshotAttributes($order);

        $order->update(['company_id' => $c2->id]);
        $order->refresh()->load(['user', 'company']);
        $new = $this->logger->snapshotAttributes($order);

        $log = $this->logger->logAttributeChanges($order, $old, $new, 'admin');

        $this->assertNotNull($log);
        $entry = $log->changes['attributes']['company_id'];
        $this->assertSame('A', $entry['old_label']);
        $this->assertSame('B', $entry['new_label']);
        $this->assertStringContainsString('«A» → «B»', $log->summary);
    }

    #[Test]
    public function item_diff_detects_added_removed_and_modified(): void
    {
        $order = Order::factory()->create();
        $product1 = Product::factory()->create(['external_id' => 'p1', 'name' => 'Один']);
        $product2 = Product::factory()->create(['external_id' => 'p2', 'name' => 'Два']);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product1->id,
            'name' => 'Один',
            'quantity' => 2,
            'base_price' => 100,
            'final_price' => 100,
            'discount_percent' => 0,
            'price' => 100,
            'subtotal' => 200,
        ]);

        $old = $this->logger->snapshotItems($order);

        // Модифицируем: p1 изменён, p2 добавлен
        $order->items()->where('product_id', $product1->id)->update(['quantity' => 3, 'subtotal' => 300]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product2->id,
            'name' => 'Два',
            'quantity' => 1,
            'base_price' => 50,
            'final_price' => 50,
            'discount_percent' => 0,
            'price' => 50,
            'subtotal' => 50,
        ]);
        $order->refresh();
        $new = $this->logger->snapshotItems($order);

        $diff = $this->logger->diffItems($old, $new);

        $this->assertCount(1, $diff['added']);
        $this->assertCount(0, $diff['removed']);
        $this->assertCount(1, $diff['modified']);
        $this->assertSame('Два', $diff['added'][0]['product_name']);
        $this->assertSame(2, $diff['modified'][0]['changes']['quantity']['old']);
        $this->assertSame(3, $diff['modified'][0]['changes']['quantity']['new']);
    }

    #[Test]
    public function log_item_changes_persists_user_and_source(): void
    {
        $actor = User::factory()->create();
        $order = Order::factory()->create();
        $product = Product::factory()->create(['external_id' => 'px']);

        $old = $this->logger->snapshotItems($order); // пусто
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => 'X',
            'quantity' => 1,
            'base_price' => 10,
            'final_price' => 10,
            'discount_percent' => 0,
            'price' => 10,
            'subtotal' => 10,
        ]);
        $order->refresh();
        $new = $this->logger->snapshotItems($order);

        $log = $this->logger->logItemChanges($order, $old, $new, 0.0, 10.0, 'admin', $actor->id);

        $this->assertNotNull($log);
        $this->assertSame('items_updated', $log->type);
        $this->assertSame('admin', $log->source);
        $this->assertSame($actor->id, $log->user_id);
        $this->assertSame('10.00', $log->new_total);
    }
}
