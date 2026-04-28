<?php

namespace Tests\Feature\Search;

use App\Enums\ReturnReason;
use App\Models\Brand;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\ReturnItem;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FuzzyDocumentSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $nikeShoes;

    private Brand $nikeBrand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->nikeBrand = Brand::create(['name' => 'Найк', 'slug' => 'naik']);
        $this->nikeShoes = Product::factory()->create([
            'name' => 'Кроссовки Найк беговые',
            'brand_id' => $this->nikeBrand->id,
        ]);
    }

    private function enableFuzzy(): void
    {
        config([
            'search-cabinet.fuzzy_documents' => true,
            'scout.driver' => 'collection',
        ]);
    }

    /**
     * Имитирует ситуацию «товар переименован, исходное имя осталось только в snapshot».
     * Используем update вместо delete — у return_items product_id cascade-удаляется
     * при удалении товара, и тест на возвраты теряет смысл.
     */
    private function renameProductBeyondLike(): void
    {
        $this->nikeShoes->update(['name' => 'XXX другое имя без совпадения']);
        $this->nikeBrand->update(['name' => 'YYY другой бренд без совпадения']);
    }

    private function fetchOrderIds(string $search): array
    {
        $response = $this->actingAs($this->user)->get('/cabinet/orders?search='.urlencode($search));
        $response->assertOk();
        if (! preg_match('/data-page="([^"]+)"/', $response->getContent(), $matches)) {
            $this->fail('Не удалось извлечь data-page из HTML-ответа');
        }
        $page = json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5), true);

        return array_column($page['props']['orders']['data'] ?? [], 'id');
    }

    private function fetchReturnIds(string $search): array
    {
        $response = $this->actingAs($this->user)->get('/cabinet/returns?search='.urlencode($search));
        $response->assertOk();
        if (! preg_match('/data-page="([^"]+)"/', $response->getContent(), $matches)) {
            $this->fail('Не удалось извлечь data-page из HTML-ответа');
        }
        $page = json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5), true);

        return array_column($page['props']['returns']['data'] ?? [], 'id');
    }

    private function fetchShipmentIds(string $search): array
    {
        $response = $this->actingAs($this->user)->get('/cabinet/shipments?search='.urlencode($search));
        $response->assertOk();
        if (! preg_match('/data-page="([^"]+)"/', $response->getContent(), $matches)) {
            $this->fail('Не удалось извлечь data-page из HTML-ответа');
        }
        $page = json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5), true);

        return array_column($page['props']['shipments']['data'] ?? [], 'id');
    }

    /**
     * Сценарий «товар удалён, имя осталось в snapshot».
     * При выключенном флаге заказ не находится через name товара (товар null),
     * при включённом — находится через product_name_snapshot.
     */
    #[Test]
    public function order_fuzzy_off_does_not_find_when_product_deleted(): void
    {
        $order = Order::factory()->create(['user_id' => $this->user->id]);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->nikeShoes->id,
            'price' => 100,
            'quantity' => 1,
            'subtotal' => 100,
        ]);
        // snapshot заполнен; имитируем переименование товара
        $this->renameProductBeyondLike();
        $item->refresh();
        $this->assertSame('Кроссовки Найк беговые', $item->name);

        $ids = $this->fetchOrderIds('Найк');
        $this->assertNotContains($order->id, $ids);
    }

    #[Test]
    public function order_fuzzy_on_finds_via_snapshot_when_product_deleted(): void
    {
        $this->enableFuzzy();

        $order = Order::factory()->create(['user_id' => $this->user->id]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->nikeShoes->id,
            'price' => 100,
            'quantity' => 1,
            'subtotal' => 100,
        ]);
        $this->renameProductBeyondLike();

        $ids = $this->fetchOrderIds('Найк');
        $this->assertContains($order->id, $ids);
    }

    #[Test]
    public function order_fuzzy_on_respects_user_scope(): void
    {
        $this->enableFuzzy();

        $other = User::factory()->create();
        $foreignOrder = Order::factory()->create(['user_id' => $other->id]);
        OrderItem::create([
            'order_id' => $foreignOrder->id,
            'product_id' => $this->nikeShoes->id,
            'price' => 100,
            'quantity' => 1,
            'subtotal' => 100,
        ]);

        $ids = $this->fetchOrderIds('Найк');
        $this->assertNotContains($foreignOrder->id, $ids);
    }

    #[Test]
    public function order_fuzzy_on_skipped_for_numeric_query(): void
    {
        $this->enableFuzzy();

        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'number' => 'ORD-2026-0001',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->nikeShoes->id,
            'price' => 100,
            'quantity' => 1,
            'subtotal' => 100,
            'brand_name_snapshot' => '4607012345678',
        ]);

        // Запрос — 13 цифр (TYPE_BARCODE) → fuzzy НЕ должен сработать,
        // даже если значение присутствует в snapshot. Проверяем, что заказ не найден
        // через fuzzy-источник (поиск идёт по точному штрихкоду через items.product.barcodes).
        $ids = $this->fetchOrderIds('4607012345678');
        $this->assertNotContains($order->id, $ids);
    }

    #[Test]
    public function return_fuzzy_off_does_not_find_when_product_deleted(): void
    {
        [$return] = $this->makeReturnWithItem();
        $this->renameProductBeyondLike();

        $ids = $this->fetchReturnIds('Найк');
        $this->assertNotContains($return->id, $ids);
    }

    #[Test]
    public function return_fuzzy_on_finds_via_snapshot_when_product_deleted(): void
    {
        $this->enableFuzzy();

        [$return] = $this->makeReturnWithItem();
        $this->renameProductBeyondLike();

        $ids = $this->fetchReturnIds('Найк');
        $this->assertContains($return->id, $ids);
    }

    #[Test]
    public function return_fuzzy_on_respects_user_scope(): void
    {
        $this->enableFuzzy();

        $other = User::factory()->create();
        [$foreignReturn] = $this->makeReturnWithItem(user: $other);

        $ids = $this->fetchReturnIds('Найк');
        $this->assertNotContains($foreignReturn->id, $ids);
    }

    #[Test]
    public function shipment_fuzzy_off_does_not_find_when_product_deleted(): void
    {
        $shipment = Shipment::factory()->create(['user_id' => $this->user->id]);
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => $this->nikeShoes->id,
            'quantity' => 1,
            'price' => 100,
            'subtotal' => 100,
        ]);
        $this->renameProductBeyondLike();

        $ids = $this->fetchShipmentIds('Найк');
        $this->assertNotContains($shipment->id, $ids);
    }

    #[Test]
    public function shipment_fuzzy_on_finds_via_snapshot_when_product_deleted(): void
    {
        $this->enableFuzzy();

        $shipment = Shipment::factory()->create(['user_id' => $this->user->id]);
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => $this->nikeShoes->id,
            'quantity' => 1,
            'price' => 100,
            'subtotal' => 100,
        ]);
        $this->renameProductBeyondLike();

        $ids = $this->fetchShipmentIds('Найк');
        $this->assertContains($shipment->id, $ids);
    }

    #[Test]
    public function shipment_fuzzy_on_respects_user_scope(): void
    {
        $this->enableFuzzy();

        $other = User::factory()->create();
        $foreignShipment = Shipment::factory()->create(['user_id' => $other->id]);
        ShipmentItem::create([
            'shipment_id' => $foreignShipment->id,
            'product_id' => $this->nikeShoes->id,
            'quantity' => 1,
            'price' => 100,
            'subtotal' => 100,
        ]);

        $ids = $this->fetchShipmentIds('Найк');
        $this->assertNotContains($foreignShipment->id, $ids);
    }

    /**
     * @return array{0: ProductReturn, 1: ReturnItem}
     */
    private function makeReturnWithItem(?User $user = null): array
    {
        $user ??= $this->user;
        $shipment = Shipment::factory()->create(['user_id' => $user->id]);
        $shipmentItem = ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => $this->nikeShoes->id,
            'quantity' => 1,
            'price' => 100,
            'subtotal' => 100,
        ]);
        $return = ProductReturn::factory()->create(['user_id' => $user->id]);
        $returnItem = ReturnItem::create([
            'return_id' => $return->id,
            'shipment_item_id' => $shipmentItem->id,
            'shipment_id' => $shipment->id,
            'product_id' => $this->nikeShoes->id,
            'quantity' => 1,
            'reason' => ReturnReason::DEFECTIVE,
            'price' => 100,
            'subtotal' => 100,
        ]);

        return [$return, $returnItem];
    }
}
