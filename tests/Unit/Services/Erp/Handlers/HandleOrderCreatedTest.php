<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\Company;
use App\Models\Order;
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
            'tax_id' => '7710140679',
            'tax_code' => '770101001',
        ]);
        $product = Product::factory()->create(['external_id' => 'prod-uuid-001']);

        $this->handler->handle([
            'event' => 'order.created',
            'message_id' => 'msg-test-001',
            'uuid' => 'order-from-manager-001',
            'number' => 'ORD-2026-0100',
            'date' => '2026-03-17T14:00:00+03:00',
            'status' => 'pending_approval',
            'type' => 'order',
            'partner_uuid' => 'erp-partner-001',
            'currency_code' => 'RUB',
            'exchange_rate' => 1.0,
            'rate_coefficient' => 1.0,
            'contractor' => [
                'country' => 'RU',
                'name' => 'ООО Тест',
                'tax_id' => '7710140679',
                'tax_code' => '770101001',
            ],
            'items' => [
                [
                    'product_uuid' => 'prod-uuid-001',
                    'quantity' => 3,
                    'price' => 1500.00,
                ],
            ],
        ]);

        $this->assertDatabaseHas('orders', [
            'uuid' => 'order-from-manager-001',
            'number' => 'ORD-2026-0100',
            'user_id' => $user->id,
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
            'event' => 'order.created',
            'message_id' => 'msg-test-002',
            'uuid' => 'order-auto-company-001',
            'status' => 'pending_approval',
            'type' => 'order',
            'partner_uuid' => 'erp-partner-002',
            'contractor' => [
                'country' => 'RU',
                'name' => 'ООО Новая Компания',
                'tax_id' => '9999999999',
            ],
            'items' => [],
        ]);

        // Контрагент должен быть создан
        $this->assertDatabaseHas('companies', [
            'tax_id' => '9999999999',
            'name' => 'ООО Новая Компания',
            'user_id' => $user->id,
        ]);

        $company = Company::withoutGlobalScopes()
            ->where('tax_id', '9999999999')
            ->first();

        $this->assertDatabaseHas('orders', [
            'uuid' => 'order-auto-company-001',
            'company_id' => $company->id,
        ]);
    }

    #[Test]
    public function creates_order_item_with_name_when_product_not_found(): void
    {
        $user = User::factory()->create(['erp_id' => 'erp-partner-003']);

        $this->handler->handle([
            'event' => 'order.created',
            'message_id' => 'msg-test-003',
            'uuid' => 'order-missing-product-001',
            'status' => 'pending_approval',
            'type' => 'order',
            'partner_uuid' => 'erp-partner-003',
            'items' => [
                [
                    'product_uuid' => 'nonexistent-product-uuid',
                    'name' => 'Товар которого нет',
                    'quantity' => 2,
                    'price' => 500.00,
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
            'event' => 'order.created',
            'message_id' => 'msg-test-004',
            'uuid' => 'order-no-partner-001',
            'status' => 'pending_approval',
            'type' => 'order',
            'partner_uuid' => 'nonexistent-partner',
            'items' => [],
        ]);

        $this->assertDatabaseHas('orders', [
            'uuid' => 'order-no-partner-001',
            'user_id' => null,
        ]);
    }

    #[Test]
    public function skips_if_uuid_missing(): void
    {
        $this->handler->handle([
            'event' => 'order.created',
            'message_id' => 'msg-test-005',
            'status' => 'pending_approval',
        ]);

        $this->assertEquals(0, Order::count());
    }

    /**
     * Регрессия: при upsert заказа уценки складская привязка позиции
     * (product_defect_id, defect_description) не должна теряться — 1С её не
     * присылает, но сайт обязан перенести со старой позиции на новую.
     */
    #[Test]
    public function it_preserves_defect_link_on_upsert(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['external_id' => 'prod-uuid-defect']);
        $defect = \App\Models\ProductDefect::factory()->create([
            'product_id' => $product->id,
            'defect_description' => 'Вскрыта упаковка',
        ]);

        Order::withoutEvents(function () use ($user, $product, $defect) {
            $order = Order::create([
                'uuid' => 'defect-order-upsert',
                'number' => 'ORD-DEFECT',
                'user_id' => $user->id,
                'type' => 'defect',
                'status' => 'ready_for_shipment',
                'total_amount' => 100,
            ]);

            $order->items()->create([
                'product_id' => $product->id,
                'name' => $product->name,
                'quantity' => 1,
                'price' => 100,
                'base_price' => 100,
                'final_price' => 100,
                'discount_percent' => 0,
                'subtotal' => 100,
                'product_defect_id' => $defect->id,
                'defect_description' => 'Вскрыта упаковка',
            ]);
        });

        // 1С возвращает заказ (тот же UUID), позиции без складских полей.
        $this->handler->handle([
            'event' => 'order.created',
            'uuid' => 'defect-order-upsert',
            'number' => '29УТ-011016',
            'status' => 'ready_for_shipment',
            'type' => 'defect',
            'items' => [
                ['product_uuid' => 'prod-uuid-defect', 'quantity' => 1, 'base_price' => 100, 'final_price' => 100, 'discount_percent' => 0],
            ],
        ]);

        $item = Order::where('uuid', 'defect-order-upsert')->first()->items->first();
        $this->assertSame($defect->id, $item->product_defect_id, 'Привязка к партии брака должна сохраниться');
        $this->assertSame('Вскрыта упаковка', $item->defect_description, 'Снапшот описания дефекта должен сохраниться');
    }

    #[Test]
    public function upserts_existing_order_on_duplicate_uuid(): void
    {
        $user = User::factory()->create();

        Order::withoutEvents(function () use ($user) {
            Order::create([
                'uuid' => 'existing-order-001',
                'number' => 'ORD-OLD',
                'user_id' => $user->id,
                'status' => 'ready_for_provision',
                'total_amount' => 1000,
            ]);
        });

        $this->handler->handle([
            'event' => 'order.created',
            'message_id' => 'msg-test-006',
            'uuid' => 'existing-order-001',
            'number' => 'ORD-NEW',
            'status' => 'pending_approval',
            'items' => [],
        ]);

        // Заказ один — дубля нет
        $this->assertEquals(1, Order::where('uuid', 'existing-order-001')->count());

        $order = Order::where('uuid', 'existing-order-001')->first();
        // Поля обновлены из нового payload
        $this->assertEquals('pending_approval', $order->status->value);
        $this->assertEquals('ORD-NEW', $order->number);
    }

    #[Test]
    public function preserves_user_comment_on_upsert(): void
    {
        // v13.8: при upsert уже существующего заказа comment из payload игнорируется,
        // чтобы не затереть то, что клиент написал на сайте при оформлении.
        $user = User::factory()->create();

        Order::withoutEvents(function () use ($user) {
            Order::create([
                'uuid' => 'order-with-user-comment',
                'number' => 'ORD-WITH-COMMENT',
                'user_id' => $user->id,
                'status' => 'pending_approval',
                'total_amount' => 0,
                'comment' => 'Доставка после 18:00, позвонить за час',
            ]);
        });

        // 1С присылает order.created для уже существующего заказа с другим комментарием
        $this->handler->handle([
            'event' => 'order.created',
            'message_id' => 'msg-test-comment-preserve',
            'uuid' => 'order-with-user-comment',
            'number' => 'ORD-WITH-COMMENT',
            'status' => 'ready_for_provision',
            'comment' => 'Комментарий менеджера из 1С',
            'items' => [],
        ]);

        $order = Order::where('uuid', 'order-with-user-comment')->first();
        $this->assertEquals('Доставка после 18:00, позвонить за час', $order->comment);
        // Остальные поля, не относящиеся к comment, обновились
        $this->assertEquals('ready_for_provision', $order->status->value);
    }

    #[Test]
    public function preserves_user_comment_on_upsert_when_payload_comment_is_null(): void
    {
        // Ещё одна грань: 1С может прислать null/отсутствие — клиентский комментарий всё равно остаётся.
        $user = User::factory()->create();

        Order::withoutEvents(function () use ($user) {
            Order::create([
                'uuid' => 'order-comment-null-payload',
                'number' => 'ORD-NULL-CMT',
                'user_id' => $user->id,
                'status' => 'pending_approval',
                'total_amount' => 0,
                'comment' => 'Хрупкий груз',
            ]);
        });

        $this->handler->handle([
            'event' => 'order.created',
            'message_id' => 'msg-test-comment-null',
            'uuid' => 'order-comment-null-payload',
            'number' => 'ORD-NULL-CMT',
            'status' => 'ready_for_provision',
            // comment в payload отсутствует
            'items' => [],
        ]);

        $order = Order::where('uuid', 'order-comment-null-payload')->first();
        $this->assertEquals('Хрупкий груз', $order->comment);
    }

    #[Test]
    public function allows_different_uuid_with_same_number(): void
    {
        // 1С при retry может послать новый uuid для того же number
        // После снятия unique с orders.number — это не должно падать
        Order::withoutEvents(function () {
            Order::create([
                'uuid' => 'order-uuid-first',
                'number' => 'ORD-SAME-NUMBER',
                'status' => 'pending_approval',
                'total_amount' => 0,
            ]);
        });

        $this->handler->handle([
            'event' => 'order.created',
            'message_id' => 'msg-test-006b',
            'uuid' => 'order-uuid-second',
            'number' => 'ORD-SAME-NUMBER',
            'status' => 'pending_approval',
            'items' => [],
        ]);

        $this->assertEquals(2, Order::withoutGlobalScopes()->where('number', 'ORD-SAME-NUMBER')->count());
    }

    #[Test]
    public function does_not_dispatch_order_created_event_to_erp(): void
    {
        // Важно: заказ от менеджера НЕ должен публиковаться обратно в 1С
        Bus::fake();

        $this->handler->handle([
            'event' => 'order.created',
            'message_id' => 'msg-test-007',
            'uuid' => 'order-no-circular-001',
            'status' => 'pending_approval',
            'type' => 'order',
            'items' => [],
        ]);

        $this->assertDatabaseHas('orders', [
            'uuid' => 'order-no-circular-001',
        ]);

        Bus::assertNotDispatched(\App\Jobs\PublishOrderToErpJob::class);
    }

    #[Test]
    public function creates_order_with_ready_for_shipment_status(): void
    {
        $this->handler->handle([
            'event' => 'order.created',
            'message_id' => 'msg-test-008',
            'uuid' => 'order-erp-status-001',
            'status' => 'ready_for_shipment',
            'type' => 'order',
            'items' => [],
        ]);

        $order = Order::where('uuid', 'order-erp-status-001')->first();
        $this->assertNotNull($order);
        $this->assertEquals('ready_for_shipment', $order->status->value);
    }

    #[Test]
    public function soft_deletes_order_when_created_with_deleted_marker(): void
    {
        $this->handler->handle([
            'event' => 'order.created',
            'message_id' => 'msg-test-009',
            'uuid' => 'order-erp-status-deleted-001',
            'status' => 'deleted',
            'type' => 'order',
            'items' => [],
        ]);

        $order = Order::withTrashed()->where('uuid', 'order-erp-status-deleted-001')->first();
        $this->assertNotNull($order);
        $this->assertEquals('closed', $order->status->value);
        $this->assertNotNull($order->deleted_at);
    }

    #[Test]
    public function maps_russian_status_from_1c_on_create(): void
    {
        $this->handler->handle([
            'event' => 'order.created',
            'message_id' => 'msg-test-010',
            'uuid' => 'order-ru-status-001',
            'status' => 'Готов к отгрузке',
            'type' => 'order',
            'items' => [],
        ]);

        $order = Order::where('uuid', 'order-ru-status-001')->first();
        $this->assertNotNull($order);
        $this->assertEquals('ready_for_shipment', $order->status->value);
    }

    #[Test]
    public function saves_delivery_address_as_text_from_payload(): void
    {
        $user = User::factory()->create(['erp_id' => 'erp-partner-addr-001']);

        $this->handler->handle([
            'event' => 'order.created',
            'message_id' => 'msg-test-addr-001',
            'uuid' => 'order-with-address-001',
            'status' => 'pending_approval',
            'type' => 'order',
            'partner_uuid' => 'erp-partner-addr-001',
            'delivery_address' => 'г. Москва, ул. Ленина, д. 1',
            'items' => [],
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
            'event' => 'order.created',
            'message_id' => 'msg-test-addr-002',
            'uuid' => 'order-no-address-001',
            'status' => 'pending_approval',
            'type' => 'order',
            'partner_uuid' => 'erp-partner-addr-002',
            'items' => [],
        ]);

        $order = Order::where('uuid', 'order-no-address-001')->first();
        $this->assertNotNull($order);
        $this->assertNull($order->delivery_address);
    }

    #[Test]
    public function saves_erp_timestamps_from_payload_v13_7(): void
    {
        $this->handler->handle([
            'event' => 'order.created',
            'message_id' => 'msg-test-erp-ts-001',
            'uuid' => 'order-erp-ts-001',
            'status' => 'pending_approval',
            'type' => 'order',
            'erp_created_at' => '2026-04-26T10:15:32+03:00',
            'erp_updated_at' => '2026-04-26T10:15:32+03:00',
            'items' => [],
        ]);

        $order = Order::where('uuid', 'order-erp-ts-001')->first();
        $this->assertNotNull($order);
        $this->assertNotNull($order->erp_created_at);
        $this->assertNotNull($order->erp_updated_at);
        // Eloquent хранит datetime в формате `Y-m-d H:i:s` без TZ; для 1С → Сайт
        // практически TZ +03:00 (Europe/Moscow), поэтому проверяем стенограмму.
        $this->assertEquals('2026-04-26 10:15:32', $order->erp_created_at->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-04-26 10:15:32', $order->erp_updated_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function normalizes_erp_timestamps_to_moscow_timezone_v13_7(): void
    {
        // 1С может прислать payload в любой TZ; на сайте всегда хранится Europe/Moscow,
        // чтобы стенограмма даты совпадала с тем, что менеджер видит в 1С.
        $this->handler->handle([
            'event' => 'order.created',
            'message_id' => 'msg-test-erp-tz-utc',
            'uuid' => 'order-erp-ts-utc',
            'status' => 'pending_approval',
            'type' => 'order',
            'erp_created_at' => '2026-04-26T07:15:32Z',  // UTC = 10:15:32 MSK
            'erp_updated_at' => '2026-04-26T11:42:09+05:00', // = 09:42:09 MSK
            'items' => [],
        ]);

        $order = Order::where('uuid', 'order-erp-ts-utc')->first();
        $this->assertNotNull($order);
        $this->assertEquals('2026-04-26 10:15:32', $order->erp_created_at->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-04-26 09:42:09', $order->erp_updated_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function leaves_erp_timestamps_null_when_absent_from_payload_v13_7(): void
    {
        $this->handler->handle([
            'event' => 'order.created',
            'message_id' => 'msg-test-erp-ts-002',
            'uuid' => 'order-erp-ts-002',
            'status' => 'pending_approval',
            'type' => 'order',
            'items' => [],
        ]);

        $order = Order::where('uuid', 'order-erp-ts-002')->first();
        $this->assertNotNull($order);
        $this->assertNull($order->erp_created_at);
        $this->assertNull($order->erp_updated_at);
    }

    #[Test]
    public function accepts_negative_discount_percent_as_markup_and_uses_final_price_for_total(): void
    {
        $product = Product::factory()->create(['external_id' => 'prod-negative-discount-001']);

        $this->handler->handle([
            'event' => 'order.created',
            'message_id' => 'msg-test-negative-discount-001',
            'uuid' => 'order-negative-discount-001',
            'status' => 'pending_approval',
            'items' => [
                [
                    'product_uuid' => 'prod-negative-discount-001',
                    'quantity' => 2,
                    'base_price' => 1000.00,
                    'discount_percent' => -15,
                    'final_price' => 1150.00,
                ],
            ],
        ]);

        $order = Order::where('uuid', 'order-negative-discount-001')->first();
        $this->assertNotNull($order);
        $this->assertEquals(2300.00, (float) $order->total_amount);
        $this->assertEquals(-15.00, (float) $order->items->first()->discount_percent);
        $this->assertEquals(1150.00, (float) $order->items->first()->final_price);
    }

    // v15.3: delivery_method (Самовывоз)
    // ──────────────────────────────────────────────

    #[Test]
    public function saves_pickup_delivery_method_from_payload(): void
    {
        $this->handler->handle([
            'event' => 'order.created',
            'message_id' => 'msg-test-dm-001',
            'uuid' => 'order-pickup-001',
            'status' => 'pending_approval',
            'delivery_method' => 'pickup',
            'items' => [],
        ]);

        $order = Order::where('uuid', 'order-pickup-001')->first();
        $this->assertNotNull($order);
        $this->assertSame('pickup', $order->delivery_method->value);
    }

    #[Test]
    public function defaults_to_delivery_when_delivery_method_missing(): void
    {
        $this->handler->handle([
            'event' => 'order.created',
            'message_id' => 'msg-test-dm-002',
            'uuid' => 'order-no-dm-001',
            'status' => 'pending_approval',
            'items' => [],
        ]);

        $order = Order::where('uuid', 'order-no-dm-001')->first();
        $this->assertNotNull($order);
        $this->assertSame('delivery', $order->delivery_method->value);
    }

    #[Test]
    public function upsert_without_delivery_method_does_not_reset_pickup(): void
    {
        Order::withoutEvents(function () {
            Order::create([
                'uuid' => 'existing-pickup-001',
                'status' => 'pending_approval',
                'delivery_method' => 'pickup',
                'total_amount' => 500,
            ]);
        });

        // 1С прислала order.created без delivery_method — способ не должен сброситься
        $this->handler->handle([
            'event' => 'order.created',
            'message_id' => 'msg-test-dm-003',
            'uuid' => 'existing-pickup-001',
            'status' => 'ready_for_provision',
            'items' => [],
        ]);

        $order = Order::where('uuid', 'existing-pickup-001')->first();
        $this->assertSame('pickup', $order->delivery_method->value);
        $this->assertSame('ready_for_provision', $order->status->value);
    }
}
