<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Erp\Handlers\HandleOrderCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandleOrderCreatedTest extends TestCase
{
    use RefreshDatabase;

    private HandleOrderCreated $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = app(HandleOrderCreated::class);
    }

    #[Test]
    public function creates_order_with_full_payload(): void
    {
        $user = User::factory()->create(['erp_id' => 'erp-partner-001']);
        $company = Company::factory()->create([
            'user_id' => $user->id,
            'tax_id'  => '7710140679',
        ]);
        $product = Product::factory()->create(['external_id' => 'prod-uuid-001']);

        $this->handler->handle([
            'event'            => 'order.created',
            'message_id'       => 'msg-test-001',
            'uuid'             => 'order-from-manager-001',
            'number'           => 'ORD-2026-0100',
            'date'             => '2026-03-17T14:00:00+03:00',
            'status'           => 'pending',
            'type'             => 'order',
            'partner_uuid'     => 'erp-partner-001',
            'currency_code'    => 'RUB',
            'exchange_rate'    => 1.0,
            'rate_coefficient' => 1.0,
            'contractor'       => [
                'country' => 'RU',
                'name'    => 'ООО Тест',
                'tax_id'  => '7710140679',
            ],
            'items' => [
                [
                    'product_uuid' => 'prod-uuid-001',
                    'quantity'     => 3,
                    'price'        => 1500.00,
                ],
            ],
        ]);

        $this->assertDatabaseHas('orders', [
            'uuid'       => 'order-from-manager-001',
            'number'     => 'ORD-2026-0100',
            'user_id'    => $user->id,
            'company_id' => $company->id,
        ]);

        $order = Order::where('uuid', 'order-from-manager-001')->first();
        $this->assertCount(1, $order->items);
        $this->assertEquals($product->id, $order->items->first()->product_id);
        $this->assertEquals(4500.00, (float) $order->total_amount);
    }

    #[Test]
    public function auto_creates_company_when_not_found_by_inn(): void
    {
        $user = User::factory()->create(['erp_id' => 'erp-partner-002']);

        $this->handler->handle([
            'event'        => 'order.created',
            'message_id'   => 'msg-test-002',
            'uuid'         => 'order-auto-company-001',
            'status'       => 'pending',
            'type'         => 'order',
            'partner_uuid' => 'erp-partner-002',
            'contractor'   => [
                'country' => 'RU',
                'name'    => 'ООО Новая Компания',
                'tax_id'  => '9999999999',
            ],
            'items' => [],
        ]);

        // Контрагент должен быть создан
        $this->assertDatabaseHas('companies', [
            'tax_id'  => '9999999999',
            'name'    => 'ООО Новая Компания',
            'user_id' => $user->id,
        ]);

        $company = Company::withoutGlobalScopes()
            ->where('tax_id', '9999999999')
            ->first();

        $this->assertDatabaseHas('orders', [
            'uuid'       => 'order-auto-company-001',
            'company_id' => $company->id,
        ]);
    }

    #[Test]
    public function creates_order_item_with_name_when_product_not_found(): void
    {
        $user = User::factory()->create(['erp_id' => 'erp-partner-003']);

        $this->handler->handle([
            'event'        => 'order.created',
            'message_id'   => 'msg-test-003',
            'uuid'         => 'order-missing-product-001',
            'status'       => 'pending',
            'type'         => 'order',
            'partner_uuid' => 'erp-partner-003',
            'items'        => [
                [
                    'product_uuid' => 'nonexistent-product-uuid',
                    'name'         => 'Товар которого нет',
                    'quantity'     => 2,
                    'price'        => 500.00,
                ],
            ],
        ]);

        $order = Order::where('uuid', 'order-missing-product-001')->first();
        $this->assertNotNull($order);
        $this->assertCount(1, $order->items);

        $item = $order->items->first();
        $this->assertNull($item->product_id);
        $this->assertEquals('Товар которого нет', $item->name);
        $this->assertEquals(1000.00, (float) $item->subtotal);
    }

    #[Test]
    public function creates_order_when_partner_not_found(): void
    {
        $this->handler->handle([
            'event'        => 'order.created',
            'message_id'   => 'msg-test-004',
            'uuid'         => 'order-no-partner-001',
            'status'       => 'pending',
            'type'         => 'order',
            'partner_uuid' => 'nonexistent-partner',
            'items'        => [],
        ]);

        $this->assertDatabaseHas('orders', [
            'uuid'    => 'order-no-partner-001',
            'user_id' => null,
        ]);
    }

    #[Test]
    public function skips_if_uuid_missing(): void
    {
        $this->handler->handle([
            'event'      => 'order.created',
            'message_id' => 'msg-test-005',
            'status'     => 'pending',
        ]);

        $this->assertEquals(0, Order::count());
    }

    #[Test]
    public function skips_if_order_already_exists_idempotency(): void
    {
        $user = User::factory()->create();

        Order::withoutEvents(function () use ($user) {
            Order::create([
                'uuid'         => 'existing-order-001',
                'user_id'      => $user->id,
                'status'       => 'confirmed',
                'total_amount' => 1000,
            ]);
        });

        $this->handler->handle([
            'event'        => 'order.created',
            'message_id'   => 'msg-test-006',
            'uuid'         => 'existing-order-001',
            'status'       => 'pending',
            'items'        => [],
        ]);

        $order = Order::where('uuid', 'existing-order-001')->first();
        // Статус не должен измениться
        $this->assertEquals('confirmed', $order->status->value);
    }

    #[Test]
    public function does_not_dispatch_order_created_event_to_erp(): void
    {
        // Важно: заказ от менеджера НЕ должен публиковаться обратно в 1С
        Bus::fake();

        $this->handler->handle([
            'event'        => 'order.created',
            'message_id'   => 'msg-test-007',
            'uuid'         => 'order-no-circular-001',
            'status'       => 'pending',
            'type'         => 'order',
            'items'        => [],
        ]);

        $this->assertDatabaseHas('orders', [
            'uuid' => 'order-no-circular-001',
        ]);

        Bus::assertNotDispatched(\App\Jobs\PublishOrderToErpJob::class);
    }

    #[Test]
    public function creates_order_with_ready_to_ship_status(): void
    {
        $this->handler->handle([
            'event'        => 'order.created',
            'message_id'   => 'msg-test-008',
            'uuid'         => 'order-erp-status-001',
            'status'       => 'ready_to_ship',
            'type'         => 'order',
            'items'        => [],
        ]);

        $order = Order::where('uuid', 'order-erp-status-001')->first();
        $this->assertNotNull($order);
        $this->assertEquals('ready_to_ship', $order->status->value);
    }

    #[Test]
    public function saves_delivery_address_as_text_from_payload(): void
    {
        $user = User::factory()->create(['erp_id' => 'erp-partner-addr-001']);

        $this->handler->handle([
            'event'            => 'order.created',
            'message_id'       => 'msg-test-addr-001',
            'uuid'             => 'order-with-address-001',
            'status'           => 'pending',
            'type'             => 'order',
            'partner_uuid'     => 'erp-partner-addr-001',
            'delivery_address' => 'г. Москва, ул. Ленина, д. 1',
            'items'            => [],
        ]);

        $order = Order::where('uuid', 'order-with-address-001')->first();
        $this->assertNotNull($order);
        $this->assertEquals('г. Москва, ул. Ленина, д. 1', $order->delivery_address);
    }

    #[Test]
    public function saves_null_delivery_address_when_not_in_payload(): void
    {
        $user = User::factory()->create(['erp_id' => 'erp-partner-addr-002']);

        $this->handler->handle([
            'event'            => 'order.created',
            'message_id'       => 'msg-test-addr-002',
            'uuid'             => 'order-no-address-001',
            'status'           => 'pending',
            'type'             => 'order',
            'partner_uuid'     => 'erp-partner-addr-002',
            'items'            => [],
        ]);

        $order = Order::where('uuid', 'order-no-address-001')->first();
        $this->assertNotNull($order);
        $this->assertNull($order->delivery_address);
    }
}
