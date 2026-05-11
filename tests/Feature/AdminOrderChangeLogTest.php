<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderChangeLog;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminOrderChangeLogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create(['name' => 'Админ']);
        $this->admin->assignRole('super-admin');

        $this->customer = User::factory()->create();
        $this->company = Company::factory()->create(['user_id' => $this->customer->id]);
    }

    #[Test]
    public function admin_update_logs_attribute_changes_with_source_and_user(): void
    {
        $order = $this->createOrderWithItem(
            deliveryAddress: 'Старый адрес',
            comment: 'Старый комментарий',
        );

        $this->actingAs($this->admin)
            ->put(route('admin.orders.update', $order), $this->buildUpdatePayload($order, [
                'delivery_address' => 'Новый адрес',
                'comment' => 'Новый комментарий',
            ]))
            ->assertRedirect();

        $log = OrderChangeLog::where('order_id', $order->id)
            ->where('type', 'attributes_updated')
            ->first();

        $this->assertNotNull($log, 'attributes_updated log must be created');
        $this->assertSame('admin', $log->source);
        $this->assertSame($this->admin->id, $log->user_id);

        $diff = $log->changes['attributes'];
        $this->assertSame('Старый адрес', $diff['delivery_address']['old']);
        $this->assertSame('Новый адрес', $diff['delivery_address']['new']);
        $this->assertSame('Старый комментарий', $diff['comment']['old']);
        $this->assertSame('Новый комментарий', $diff['comment']['new']);
    }

    #[Test]
    public function admin_update_logs_item_changes_with_source_and_user(): void
    {
        $order = $this->createOrderWithItem(quantity: 2, price: 100);

        $payload = $this->buildUpdatePayload($order, [
            'items' => [[
                'id' => $order->items()->first()->id,
                'product_id' => $order->items()->first()->product_id,
                'name' => $order->items()->first()->name,
                'price' => 100,
                'base_price' => 100,
                'final_price' => 100,
                'discount_percent' => 0,
                'quantity' => 5,
            ]],
        ]);

        $this->actingAs($this->admin)
            ->put(route('admin.orders.update', $order), $payload)
            ->assertRedirect();

        $log = OrderChangeLog::where('order_id', $order->id)
            ->where('type', 'items_updated')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('admin', $log->source);
        $this->assertSame($this->admin->id, $log->user_id);

        $modified = $log->changes['modified'];
        $this->assertCount(1, $modified);
        $this->assertSame(2, $modified[0]['changes']['quantity']['old']);
        $this->assertSame(5, $modified[0]['changes']['quantity']['new']);
    }

    #[Test]
    public function admin_update_without_changes_does_not_create_logs(): void
    {
        $order = $this->createOrderWithItem(quantity: 2, price: 100, deliveryAddress: 'Адрес', comment: 'ком');

        $this->actingAs($this->admin)
            ->put(route('admin.orders.update', $order), $this->buildUpdatePayload($order))
            ->assertRedirect();

        $this->assertDatabaseCount('order_change_logs', 0);
    }

    #[Test]
    public function admin_show_exposes_change_logs(): void
    {
        $order = $this->createOrderWithItem(deliveryAddress: 'Адрес 1');

        // Создаём вручную лог для проверки отображения
        OrderChangeLog::create([
            'order_id' => $order->id,
            'type' => 'attributes_updated',
            'summary' => 'Адрес доставки: «Адрес 1» → «Адрес 2»',
            'changes' => ['attributes' => ['delivery_address' => [
                'label' => 'Адрес доставки',
                'old' => 'Адрес 1',
                'new' => 'Адрес 2',
            ]]],
            'source' => 'admin',
            'user_id' => $this->admin->id,
            'old_total' => 100,
            'new_total' => 100,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Pages/Orders/Show', false)
                ->has('order.change_logs', 1)
                ->where('order.change_logs.0.type', 'attributes_updated')
                ->where('order.change_logs.0.source', 'admin')
                ->where('order.change_logs.0.user_name', $this->admin->name)
            );
    }

    private function createOrderWithItem(
        int $quantity = 2,
        float $price = 100,
        ?string $deliveryAddress = null,
        ?string $comment = null,
    ): Order {
        $product = Product::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'delivery_address' => $deliveryAddress,
            'comment' => $comment,
            'status' => 'pending_approval',
            'currency_code' => 'RUB',
            'total_amount' => $price * $quantity,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'price' => $price,
            'base_price' => $price,
            'final_price' => $price,
            'discount_percent' => 0,
            'quantity' => $quantity,
            'subtotal' => $price * $quantity,
        ]);

        return $order->refresh();
    }

    private function buildUpdatePayload(Order $order, array $overrides = []): array
    {
        $items = $order->items->map(fn ($item) => [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'name' => $item->name,
            'price' => (float) $item->price,
            'base_price' => (float) $item->base_price,
            'final_price' => (float) $item->final_price,
            'discount_percent' => (float) $item->discount_percent,
            'quantity' => (int) $item->quantity,
        ])->all();

        return array_replace([
            'user_id' => $order->user_id,
            'company_id' => $order->company_id,
            'delivery_address' => $order->delivery_address,
            'status' => $order->status?->value ?? 'pending',
            'comment' => $order->comment,
            'currency_code' => $order->currency_code ?? 'RUB',
            'items' => $items,
        ], $overrides);
    }
}
