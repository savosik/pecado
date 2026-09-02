<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\ProductExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Условия отбора конструктора выгрузок по покупкам клиента:
 * «Содержится в заказах», «Содержится в реализациях», «Когда-либо заказывался».
 *
 * Кейс: клиент выгружает себе на сайт ровно тот перечень товаров,
 * которые он у нас заказывал.
 */
class ProductExportPurchaseFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected ProductExportService $service;

    protected User $client;

    protected User $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProductExportService::class);
        $this->client = User::factory()->create();
        $this->stranger = User::factory()->create();
    }

    protected function filters(string $field, string $operator, mixed $value): array
    {
        return [
            'logic' => 'and',
            'conditions' => [
                ['type' => 'condition', 'field' => $field, 'operator' => $operator, 'value' => $value],
            ],
        ];
    }

    public function test_in_orders_returns_only_products_from_selected_orders(): void
    {
        [$inOrder, $notInOrder] = Product::factory()->count(2)->create();

        $order = Order::factory()->create(['user_id' => $this->client->id]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $inOrder->id]);

        $otherOrder = Order::factory()->create(['user_id' => $this->client->id]);
        OrderItem::factory()->create(['order_id' => $otherOrder->id, 'product_id' => $notInOrder->id]);

        $ids = $this->service
            ->buildQuery($this->filters('in_orders', 'in', [$order->id]), $this->client->id)
            ->pluck('id');

        $this->assertEquals([$inOrder->id], $ids->all());
    }

    public function test_in_orders_ignores_foreign_orders(): void
    {
        $product = Product::factory()->create();

        $foreignOrder = Order::factory()->create(['user_id' => $this->stranger->id]);
        OrderItem::factory()->create(['order_id' => $foreignOrder->id, 'product_id' => $product->id]);

        // Клиент подставил id чужого заказа — состав чужого заказа не утекает
        $ids = $this->service
            ->buildQuery($this->filters('in_orders', 'in', [$foreignOrder->id]), $this->client->id)
            ->pluck('id');

        $this->assertTrue($ids->isEmpty());
    }

    public function test_in_orders_not_in_excludes_products_from_selected_orders(): void
    {
        [$inOrder, $notInOrder] = Product::factory()->count(2)->create();

        $order = Order::factory()->create(['user_id' => $this->client->id]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $inOrder->id]);

        $ids = $this->service
            ->buildQuery($this->filters('in_orders', 'not_in', [$order->id]), $this->client->id)
            ->pluck('id');

        $this->assertEquals([$notInOrder->id], $ids->all());
    }

    public function test_in_shipments_returns_only_products_from_selected_shipments(): void
    {
        [$shipped, $other] = Product::factory()->count(2)->create();

        $shipment = Shipment::factory()->create(['user_id' => $this->client->id, 'number' => 'Р-001']);
        ShipmentItem::factory()->create(['shipment_id' => $shipment->id, 'product_id' => $shipped->id]);

        $otherShipment = Shipment::factory()->create(['user_id' => $this->client->id, 'number' => 'Р-002']);
        ShipmentItem::factory()->create(['shipment_id' => $otherShipment->id, 'product_id' => $other->id]);

        $ids = $this->service
            ->buildQuery($this->filters('in_shipments', 'in', [$shipment->id]), $this->client->id)
            ->pluck('id');

        $this->assertEquals([$shipped->id], $ids->all());
    }

    public function test_in_shipments_ignores_foreign_shipments(): void
    {
        $product = Product::factory()->create();

        $foreign = Shipment::factory()->create(['user_id' => $this->stranger->id, 'number' => 'Р-003']);
        ShipmentItem::factory()->create(['shipment_id' => $foreign->id, 'product_id' => $product->id]);

        $ids = $this->service
            ->buildQuery($this->filters('in_shipments', 'in', [$foreign->id]), $this->client->id)
            ->pluck('id');

        $this->assertTrue($ids->isEmpty());
    }

    public function test_ever_ordered_true_returns_products_the_client_ordered(): void
    {
        [$ordered, $never] = Product::factory()->count(2)->create();

        $order = Order::factory()->create(['user_id' => $this->client->id]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $ordered->id]);

        // Заказ чужого клиента не делает товар «заказанным» для нашего
        $foreignOrder = Order::factory()->create(['user_id' => $this->stranger->id]);
        OrderItem::factory()->create(['order_id' => $foreignOrder->id, 'product_id' => $never->id]);

        $ids = $this->service
            ->buildQuery($this->filters('ever_ordered', '=', true), $this->client->id)
            ->pluck('id');

        $this->assertEquals([$ordered->id], $ids->all());
    }

    public function test_ever_ordered_false_returns_products_the_client_never_ordered(): void
    {
        [$ordered, $never] = Product::factory()->count(2)->create();

        $order = Order::factory()->create(['user_id' => $this->client->id]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $ordered->id]);

        $ids = $this->service
            ->buildQuery($this->filters('ever_ordered', '=', false), $this->client->id)
            ->pluck('id');

        $this->assertEquals([$never->id], $ids->all());
    }

    public function test_ever_ordered_without_client_is_ignored(): void
    {
        Product::factory()->count(2)->create();

        $count = $this->service
            ->buildQuery($this->filters('ever_ordered', '=', true), null)
            ->count();

        $this->assertEquals(2, $count);
    }

    public function test_cabinet_filter_options_returns_only_own_orders(): void
    {
        $own = Order::factory()->create(['user_id' => $this->client->id]);
        Order::factory()->create(['user_id' => $this->stranger->id]);

        $response = $this->actingAs($this->client)
            ->getJson('/cabinet/product-exports/filter-options?type=orders');

        $response->assertOk();
        $this->assertEquals([$own->id], collect($response->json())->pluck('id')->all());
        $this->assertStringContainsString($own->number, $response->json()[0]['name']);
    }

    public function test_cabinet_filter_options_returns_only_own_shipments(): void
    {
        $own = Shipment::factory()->create(['user_id' => $this->client->id, 'number' => 'Р-100']);
        Shipment::factory()->create(['user_id' => $this->stranger->id, 'number' => 'Р-200']);

        $response = $this->actingAs($this->client)
            ->getJson('/cabinet/product-exports/filter-options?type=shipments');

        $response->assertOk();
        $this->assertEquals([$own->id], collect($response->json())->pluck('id')->all());
        $this->assertStringContainsString('Р-100', $response->json()[0]['name']);
    }

    public function test_available_filters_include_purchase_fields(): void
    {
        $groups = collect($this->service->getAvailableFilters());
        $purchases = $groups->firstWhere('group', 'Покупки');

        $this->assertNotNull($purchases);
        $keys = collect($purchases['fields'])->pluck('key');
        $this->assertTrue($keys->contains('in_orders'));
        $this->assertTrue($keys->contains('in_shipments'));
        $this->assertTrue($keys->contains('ever_ordered'));
    }
}
