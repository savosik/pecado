<?php

namespace Tests\Unit\Services\Order;

use App\Models\Order;
use App\Models\OrderChangeLog;
use App\Models\Product;
use App\Models\User;
use App\Services\Order\OrderChangeAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderChangeAggregatorTest extends TestCase
{
    use RefreshDatabase;

    private OrderChangeAggregator $aggregator;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->aggregator = new OrderChangeAggregator;
        $this->user = User::factory()->create();
    }

    private function order(): Order
    {
        return Order::factory()->create(['user_id' => $this->user->id]);
    }

    private function log(Order $order, array $changes, string $createdAt): OrderChangeLog
    {
        $log = OrderChangeLog::create([
            'order_id' => $order->id,
            'type' => 'items_updated',
            'summary' => '…',
            'changes' => array_merge(['added' => [], 'removed' => [], 'modified' => []], $changes),
            'source' => 'erp',
        ]);

        // created_at не входит в fillable — задаём явно, чтобы свёртка шла
        // в правильном хронологическом порядке.
        $log->created_at = $createdAt;
        $log->save();

        return $log;
    }

    private function userOrders(): \Illuminate\Database\Eloquent\Collection
    {
        return Order::where('user_id', $this->user->id)->get();
    }

    #[Test]
    public function removed_then_added_folds_into_a_single_changed_row(): void
    {
        $p = Product::factory()->create(['name' => 'Апельсин', 'slug' => 'apelsin']);
        $order = $this->order();

        // Сначала сняли 5, затем добавили 6 — нетто «было 5 → стало 6».
        $this->log($order, ['removed' => [[
            'product_id' => $p->id, 'slug' => $p->slug, 'product_name' => $p->name, 'quantity' => 5, 'price' => 10,
        ]]], '2026-07-10 10:00:00');
        $this->log($order, ['added' => [[
            'product_id' => $p->id, 'slug' => $p->slug, 'product_name' => $p->name, 'quantity' => 6, 'price' => 10,
        ]]], '2026-07-11 12:00:00');

        $rows = $this->aggregator->flatten($this->userOrders());

        $this->assertCount(1, $rows);
        $this->assertSame('changed', $rows[0]['type']);
        $this->assertSame(5, $rows[0]['from']);
        $this->assertSame(6, $rows[0]['to']);
        $this->assertSame('Апельсин', $rows[0]['product_name']);
        $this->assertSame('apelsin', $rows[0]['slug']);
        // Время — последнего лога, затронувшего товар.
        $this->assertSame('2026-07-11', $rows[0]['changed_at']->toDateString());
    }

    #[Test]
    public function net_zero_movement_produces_no_row(): void
    {
        $p = Product::factory()->create(['slug' => 'zero']);
        $order = $this->order();

        $this->log($order, ['removed' => [[
            'product_id' => $p->id, 'slug' => $p->slug, 'product_name' => 'X', 'quantity' => 5, 'price' => 10,
        ]]], '2026-07-10 10:00:00');
        $this->log($order, ['added' => [[
            'product_id' => $p->id, 'slug' => $p->slug, 'product_name' => 'X', 'quantity' => 5, 'price' => 10,
        ]]], '2026-07-11 10:00:00');

        $this->assertCount(0, $this->aggregator->flatten($this->userOrders()));
    }

    #[Test]
    public function classifies_added_removed_and_partial_change_with_external_id(): void
    {
        $added = Product::factory()->create(['name' => 'A', 'slug' => 'a', 'external_id' => 'uuid-a']);
        $removed = Product::factory()->create(['name' => 'B', 'slug' => 'b', 'external_id' => 'uuid-b']);
        $partial = Product::factory()->create(['name' => 'C', 'slug' => 'c', 'external_id' => 'uuid-c']);
        $order = $this->order();

        $this->log($order, [
            'added' => [[
                'product_id' => $added->id, 'slug' => $added->slug, 'product_name' => 'A', 'quantity' => 3, 'price' => 10,
            ]],
            'removed' => [[
                'product_id' => $removed->id, 'slug' => $removed->slug, 'product_name' => 'B', 'quantity' => 4, 'price' => 10,
            ]],
            'modified' => [[
                'product_id' => $partial->id, 'slug' => $partial->slug, 'product_name' => 'C',
                'changes' => ['quantity' => ['old' => 7, 'new' => 6]],
            ]],
        ], '2026-07-10 10:00:00');

        $rows = collect($this->aggregator->flatten($this->userOrders()))->keyBy('product_name');

        $this->assertSame('added', $rows['A']['type']);
        $this->assertSame(0, $rows['A']['from']);
        $this->assertSame(3, $rows['A']['to']);
        $this->assertSame('uuid-a', $rows['A']['external_id']);

        $this->assertSame('removed', $rows['B']['type']);
        $this->assertSame(4, $rows['B']['from']);
        $this->assertSame(0, $rows['B']['to']);

        $this->assertSame('changed', $rows['C']['type']);
        $this->assertSame(7, $rows['C']['from']);
        $this->assertSame(6, $rows['C']['to']);
        $this->assertSame('uuid-c', $rows['C']['external_id']);
    }

    #[Test]
    public function price_only_modification_is_ignored(): void
    {
        $p = Product::factory()->create(['slug' => 'price']);
        $order = $this->order();

        $this->log($order, ['modified' => [[
            'product_id' => $p->id, 'slug' => $p->slug, 'product_name' => 'X',
            'changes' => ['final_price' => ['old' => 100, 'new' => 90]],
        ]]], '2026-07-10 10:00:00');

        $this->assertCount(0, $this->aggregator->flatten($this->userOrders()));
    }

    #[Test]
    public function grouped_by_order_matches_flatten_counts(): void
    {
        $a = Product::factory()->create(['name' => 'A', 'slug' => 'a']);
        $b = Product::factory()->create(['name' => 'B', 'slug' => 'b']);
        $order = $this->order();

        $this->log($order, [
            'added' => [['product_id' => $a->id, 'slug' => $a->slug, 'product_name' => 'A', 'quantity' => 2, 'price' => 10]],
            'removed' => [['product_id' => $b->id, 'slug' => $b->slug, 'product_name' => 'B', 'quantity' => 1, 'price' => 10]],
        ], '2026-07-10 10:00:00');

        $grouped = $this->aggregator->groupedByOrder($this->userOrders());

        $this->assertArrayHasKey($order->id, $grouped);
        $this->assertSame(2, $grouped[$order->id]['count']);
        $this->assertCount(1, $grouped[$order->id]['added']);
        $this->assertCount(1, $grouped[$order->id]['removed']);
    }

    #[Test]
    public function flatten_covers_multiple_orders(): void
    {
        $p = Product::factory()->create(['name' => 'Товар', 'slug' => 't']);
        $o1 = $this->order();
        $o2 = $this->order();

        $this->log($o1, ['added' => [['product_id' => $p->id, 'slug' => $p->slug, 'product_name' => 'Товар', 'quantity' => 1, 'price' => 10]]], '2026-07-10 10:00:00');
        $this->log($o2, ['removed' => [['product_id' => $p->id, 'slug' => $p->slug, 'product_name' => 'Товар', 'quantity' => 2, 'price' => 10]]], '2026-07-11 10:00:00');

        $rows = $this->aggregator->flatten($this->userOrders());
        $this->assertCount(2, $rows);
        $this->assertSame([$o1->id, $o2->id], collect($rows)->pluck('order_id')->sort()->values()->all());
    }
}
