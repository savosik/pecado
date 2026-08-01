<?php

namespace Tests\Feature\Erp;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderChangeLog;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Erp\Handlers\HandleOrderCreated;
use App\Services\Erp\Handlers\HandleOrderUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Организация и склад проведения заказа (v15.8.0, карточка org-03).
 *
 * Оба поля присылает только 1С. Ключевое требование — отсутствие поля в payload
 * никогда не сбрасывает уже сохранённое значение.
 */
class OrderOrganizationTest extends TestCase
{
    use RefreshDatabase;

    private const ORDER_UUID = '550e8400-e29b-41d4-a716-446655440001';

    private const ORG_UUID = '3d0a3eb9-0c23-11ee-8ddc-ee348b24c7ce';

    private const WAREHOUSE_UUID = 'f8083799-0838-11e0-a1ea-505054503030';

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['erp_id' => '550e8400-e29b-41d4-a716-446655440002']);
        $this->product = Product::factory()->create(['external_id' => '550e8400-e29b-41d4-a716-446655440004']);
    }

    private function createdPayload(array $override = []): array
    {
        return array_merge([
            'event' => 'order.created',
            'message_id' => 'msg-order-org-created',
            'uuid' => self::ORDER_UUID,
            'number' => '29УТ-000123',
            'status' => 'pending_approval',
            'partner_uuid' => $this->user->erp_id,
            'contractor' => [
                'uuid' => '550e8400-e29b-41d4-a716-446655440003',
                'tax_id' => '7710140679',
                'name' => 'ООО Клиент',
            ],
            'items' => [[
                'product_uuid' => $this->product->external_id,
                'quantity' => 2,
                'base_price' => 100,
                'final_price' => 100,
            ]],
        ], $override);
    }

    private function handleCreated(array $payload): void
    {
        app(HandleOrderCreated::class)->handle($payload);
    }

    private function handleUpdated(array $payload): void
    {
        app(HandleOrderUpdated::class)->handle(array_merge([
            'event' => 'order.updated',
            'message_id' => 'msg-order-org-updated',
            'uuid' => self::ORDER_UUID,
        ], $payload));
    }

    // ──────────────────────────────────────────────
    // order.created
    // ──────────────────────────────────────────────

    #[Test]
    public function order_created_with_organization_and_warehouse_links_both(): void
    {
        $organization = Organization::factory()->create(['external_id' => self::ORG_UUID]);
        $warehouse = Warehouse::factory()->create(['external_id' => self::WAREHOUSE_UUID]);

        $this->handleCreated($this->createdPayload([
            'organization' => ['uuid' => self::ORG_UUID],
            'warehouse_uuid' => self::WAREHOUSE_UUID,
        ]));

        $order = Order::where('uuid', self::ORDER_UUID)->firstOrFail();

        $this->assertSame($organization->id, $order->organization_id);
        $this->assertSame($warehouse->id, $order->warehouse_id);
    }

    #[Test]
    public function order_created_without_organization_is_still_created(): void
    {
        $this->handleCreated($this->createdPayload());

        $order = Order::where('uuid', self::ORDER_UUID)->firstOrFail();

        $this->assertNull($order->organization_id);
        $this->assertNull($order->warehouse_id);
    }

    #[Test]
    public function unknown_organization_uuid_creates_stub_and_links_it(): void
    {
        $this->handleCreated($this->createdPayload([
            'organization' => ['uuid' => self::ORG_UUID],
        ]));

        $order = Order::where('uuid', self::ORDER_UUID)->firstOrFail();
        $organization = Organization::where('external_id', self::ORG_UUID)->firstOrFail();

        $this->assertTrue($organization->is_stub);
        $this->assertSame($organization->id, $order->organization_id);
    }

    #[Test]
    public function unknown_warehouse_uuid_leaves_null_without_creating_warehouse(): void
    {
        $this->handleCreated($this->createdPayload([
            'warehouse_uuid' => 'не-заведённый-склад',
        ]));

        $order = Order::where('uuid', self::ORDER_UUID)->firstOrFail();

        $this->assertNull($order->warehouse_id);
        $this->assertDatabaseMissing('warehouses', ['external_id' => 'не-заведённый-склад']);
    }

    /**
     * Заказ оформлен на сайте, затем 1С прислала его обратно с организацией.
     * Значение 1С авторитетно: она выбирает организацию, сайт её не определяет.
     */
    #[Test]
    public function upsert_writes_organization_onto_order_created_by_site(): void
    {
        $organization = Organization::factory()->create(['external_id' => self::ORG_UUID]);
        $company = Company::factory()->create(['user_id' => $this->user->id, 'tax_id' => '7710140679']);

        Order::withoutEvents(fn () => Order::create([
            'uuid' => self::ORDER_UUID,
            'user_id' => $this->user->id,
            'company_id' => $company->id,
            'status' => 'pending_approval',
            'total_amount' => 200,
        ]));

        $this->handleCreated($this->createdPayload([
            'organization' => ['uuid' => self::ORG_UUID],
        ]));

        $this->assertSame($organization->id, Order::where('uuid', self::ORDER_UUID)->first()->organization_id);
    }

    // ──────────────────────────────────────────────
    // order.updated
    // ──────────────────────────────────────────────

    #[Test]
    public function order_updated_sets_organization_on_existing_order(): void
    {
        $this->handleCreated($this->createdPayload());
        $organization = Organization::factory()->create(['external_id' => self::ORG_UUID]);

        $this->handleUpdated([
            'status' => 'ready_for_shipment',
            'organization' => ['uuid' => self::ORG_UUID],
        ]);

        $this->assertSame($organization->id, Order::where('uuid', self::ORDER_UUID)->first()->organization_id);
    }

    /**
     * Главный кейс переходного периода: 1С шлёт организацию не во всех сообщениях.
     */
    #[Test]
    public function order_updated_without_organization_does_not_reset_it(): void
    {
        $organization = Organization::factory()->create(['external_id' => self::ORG_UUID]);
        $warehouse = Warehouse::factory()->create(['external_id' => self::WAREHOUSE_UUID]);

        $this->handleCreated($this->createdPayload([
            'organization' => ['uuid' => self::ORG_UUID],
            'warehouse_uuid' => self::WAREHOUSE_UUID,
        ]));

        $this->handleUpdated(['status' => 'shipping']);

        $order = Order::where('uuid', self::ORDER_UUID)->first();

        $this->assertSame($organization->id, $order->organization_id);
        $this->assertSame($warehouse->id, $order->warehouse_id);
    }

    #[Test]
    public function explicit_null_organization_does_not_reset_it(): void
    {
        $organization = Organization::factory()->create(['external_id' => self::ORG_UUID]);

        $this->handleCreated($this->createdPayload([
            'organization' => ['uuid' => self::ORG_UUID],
        ]));

        $this->handleUpdated(['status' => 'shipping', 'organization' => null]);

        $this->assertSame($organization->id, Order::where('uuid', self::ORDER_UUID)->first()->organization_id);
    }

    #[Test]
    public function changing_organization_is_written_to_change_log(): void
    {
        $first = Organization::factory()->create(['external_id' => self::ORG_UUID, 'name' => 'ООО Пекадо']);
        $second = Organization::factory()->create([
            'external_id' => '9da1768a-40d4-11e1-a692-001e6711ed1d',
            'name' => 'Реклама',
        ]);

        $this->handleCreated($this->createdPayload([
            'organization' => ['uuid' => $first->external_id],
        ]));

        $this->handleUpdated([
            'status' => 'shipping',
            'organization' => ['uuid' => $second->external_id],
        ]);

        $order = Order::where('uuid', self::ORDER_UUID)->first();

        $this->assertSame($second->id, $order->organization_id);

        $log = OrderChangeLog::where('order_id', $order->id)
            ->where('type', 'attributes_updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'Смена организации должна попасть в журнал изменений заказа');
        $this->assertStringContainsString('Организация', $log->summary);
        $this->assertStringContainsString('ООО Пекадо', $log->summary);
        $this->assertStringContainsString('Реклама', $log->summary);
    }

    /**
     * Организация и склад независимы: 1С может прислать одно без другого.
     */
    #[Test]
    public function warehouse_can_arrive_without_organization(): void
    {
        $warehouse = Warehouse::factory()->create(['external_id' => self::WAREHOUSE_UUID]);

        $this->handleCreated($this->createdPayload(['warehouse_uuid' => self::WAREHOUSE_UUID]));

        $order = Order::where('uuid', self::ORDER_UUID)->first();

        $this->assertSame($warehouse->id, $order->warehouse_id);
        $this->assertNull($order->organization_id);
    }

    /**
     * Подсказка из payload дозаполняет заглушку, но подтверждать карточку
     * должен админ — is_stub снимается только в админке.
     */
    #[Test]
    public function organization_hint_fills_stub_name(): void
    {
        $this->handleCreated($this->createdPayload([
            'organization' => ['uuid' => self::ORG_UUID, 'name' => 'ООО Пекадо', 'tax_id' => '7710140679'],
        ]));

        $organization = Organization::where('external_id', self::ORG_UUID)->firstOrFail();

        $this->assertSame('ООО Пекадо', $organization->name);
        $this->assertSame('7710140679', $organization->tax_id);
        $this->assertTrue($organization->is_stub);
    }
}
