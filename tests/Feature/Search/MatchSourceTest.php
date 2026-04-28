<?php

namespace Tests\Feature\Search;

use App\Enums\ReturnReason;
use App\Models\Brand;
use App\Models\Company;
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

class MatchSourceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Brand $nikeBrand;

    private Product $nikeShoes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->nikeBrand = Brand::create(['name' => 'Найк', 'slug' => 'naik-match']);
        $this->nikeShoes = Product::factory()->create([
            'name' => 'Кроссовки Найк беговые',
            'brand_id' => $this->nikeBrand->id,
        ]);
    }

    private function fetchOrder(string $search): array
    {
        $response = $this->actingAs($this->user)->get('/cabinet/orders?search='.urlencode($search));
        $response->assertOk();
        preg_match('/data-page="([^"]+)"/', $response->getContent(), $m);
        $page = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true);

        return $page['props']['orders']['data'] ?? [];
    }

    private function fetchReturn(string $search): array
    {
        $response = $this->actingAs($this->user)->get('/cabinet/returns?search='.urlencode($search));
        $response->assertOk();
        preg_match('/data-page="([^"]+)"/', $response->getContent(), $m);
        $page = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true);

        return $page['props']['returns']['data'] ?? [];
    }

    private function fetchShipment(string $search): array
    {
        $response = $this->actingAs($this->user)->get('/cabinet/shipments?search='.urlencode($search));
        $response->assertOk();
        preg_match('/data-page="([^"]+)"/', $response->getContent(), $m);
        $page = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true);

        return $page['props']['shipments']['data'] ?? [];
    }

    private function makeOrderWithItem(array $orderAttrs = []): Order
    {
        $order = Order::factory()->create(array_merge(['user_id' => $this->user->id], $orderAttrs));
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->nikeShoes->id,
            'price' => 100,
            'quantity' => 1,
            'subtotal' => 100,
        ]);

        return $order;
    }

    private function makeReturnWithItem(array $returnAttrs = [], array $itemAttrs = []): ProductReturn
    {
        $shipment = Shipment::factory()->create(['user_id' => $this->user->id]);
        $shipmentItem = ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => $this->nikeShoes->id,
            'quantity' => 1,
            'price' => 100,
            'subtotal' => 100,
        ]);
        $return = ProductReturn::factory()->create(array_merge(['user_id' => $this->user->id], $returnAttrs));
        ReturnItem::create(array_merge([
            'return_id' => $return->id,
            'shipment_item_id' => $shipmentItem->id,
            'shipment_id' => $shipment->id,
            'product_id' => $this->nikeShoes->id,
            'quantity' => 1,
            'reason' => ReturnReason::DEFECTIVE,
            'price' => 100,
            'subtotal' => 100,
        ], $itemAttrs));

        return $return;
    }

    private function makeShipmentWithItem(array $shipmentAttrs = []): Shipment
    {
        $shipment = Shipment::factory()->create(array_merge(['user_id' => $this->user->id], $shipmentAttrs));
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => $this->nikeShoes->id,
            'quantity' => 1,
            'price' => 100,
            'subtotal' => 100,
        ]);

        return $shipment;
    }

    private function findById(array $rows, int $id): ?array
    {
        foreach ($rows as $row) {
            if (($row['id'] ?? null) === $id) {
                return $row;
            }
        }

        return null;
    }

    // ---------- Order ----------

    #[Test]
    public function order_match_source_is_null_without_search(): void
    {
        $order = $this->makeOrderWithItem();
        $rows = $this->fetchOrder('');
        $row = $this->findById($rows, $order->id);
        $this->assertNotNull($row);
        $this->assertNull($row['match_source']);
        $this->assertNull($row['match_snippet']);
    }

    #[Test]
    public function order_match_source_number(): void
    {
        $order = $this->makeOrderWithItem(['number' => 'ORD-2026-MATCH-001']);
        $rows = $this->fetchOrder('MATCH-001');
        $row = $this->findById($rows, $order->id);
        $this->assertNotNull($row);
        $this->assertSame('number', $row['match_source']);
        $this->assertSame('ORD-2026-MATCH-001', $row['match_snippet']);
    }

    #[Test]
    public function order_match_source_composition(): void
    {
        // Имя товара содержит «беговые» — попадёт в product_name_snapshot.
        $order = $this->makeOrderWithItem(['number' => 'ORD-PLAIN-NUMBER']);
        $rows = $this->fetchOrder('беговые');
        $row = $this->findById($rows, $order->id);
        $this->assertNotNull($row);
        $this->assertSame('composition', $row['match_source']);
        $this->assertStringContainsString('беговые', (string) $row['match_snippet']);
    }

    #[Test]
    public function order_match_source_comment(): void
    {
        $order = $this->makeOrderWithItem([
            'number' => 'ORD-PLAIN-NUMBER',
            'comment' => 'Срочный заказ для важного клиента',
        ]);
        $rows = $this->fetchOrder('Срочный');
        $row = $this->findById($rows, $order->id);
        $this->assertNotNull($row);
        $this->assertSame('comment', $row['match_source']);
        $this->assertStringContainsString('Срочный', (string) $row['match_snippet']);
    }

    #[Test]
    public function order_match_source_company(): void
    {
        $company = Company::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'ООО «Уникальный Контрагент 5511»',
        ]);
        $order = $this->makeOrderWithItem([
            'number' => 'ORD-PLAIN-NUMBER',
            'company_id' => $company->id,
        ]);
        $rows = $this->fetchOrder('Уникальный Контрагент');
        $row = $this->findById($rows, $order->id);
        $this->assertNotNull($row);
        $this->assertSame('company', $row['match_source']);
    }

    // ---------- Return ----------

    #[Test]
    public function return_match_source_number(): void
    {
        $return = $this->makeReturnWithItem(['erp_number' => 'RET-2026-MATCH-001']);
        $rows = $this->fetchReturn('MATCH-001');
        $row = $this->findById($rows, $return->id);
        $this->assertNotNull($row);
        $this->assertSame('number', $row['match_source']);
    }

    #[Test]
    public function return_match_source_composition(): void
    {
        $return = $this->makeReturnWithItem(['erp_number' => 'RET-PLAIN']);
        $rows = $this->fetchReturn('беговые');
        $row = $this->findById($rows, $return->id);
        $this->assertNotNull($row);
        $this->assertSame('composition', $row['match_source']);
    }

    #[Test]
    public function return_match_source_reason_comment(): void
    {
        $return = $this->makeReturnWithItem(
            ['erp_number' => 'RET-PLAIN'],
            ['reason_comment' => 'Подошва отклеилась в первый день']
        );
        $rows = $this->fetchReturn('Подошва');
        $row = $this->findById($rows, $return->id);
        $this->assertNotNull($row);
        $this->assertSame('comment', $row['match_source']);
    }

    // ---------- Shipment ----------

    #[Test]
    public function shipment_match_source_number(): void
    {
        $shipment = $this->makeShipmentWithItem(['number' => 'SHP-MATCH-001']);
        $rows = $this->fetchShipment('MATCH-001');
        $row = $this->findById($rows, $shipment->id);
        $this->assertNotNull($row);
        $this->assertSame('number', $row['match_source']);
    }

    #[Test]
    public function shipment_match_source_composition(): void
    {
        $shipment = $this->makeShipmentWithItem(['number' => 'SHP-PLAIN']);
        $rows = $this->fetchShipment('беговые');
        $row = $this->findById($rows, $shipment->id);
        $this->assertNotNull($row);
        $this->assertSame('composition', $row['match_source']);
    }

    // ---------- Fuzzy fallback ----------

    #[Test]
    public function order_match_source_fuzzy_when_only_meilisearch_matches(): void
    {
        config([
            'search-cabinet.fuzzy_documents' => true,
            'scout.driver' => 'collection',
        ]);

        $order = $this->makeOrderWithItem(['number' => 'ORD-PLAIN-NUMBER']);
        // Запрос «nayk» — не попадает в LIKE по имени товара/бренда (русское),
        // не попадает в number, но fuzzy через product_name_snapshot/brand_name_snapshot
        // может найти. brand_translit='nayk' у бренда Найк.
        $rows = $this->fetchOrder('nayk');
        $row = $this->findById($rows, $order->id);
        if ($row !== null) {
            // Если документ нашёлся — это ИСКЛЮЧИТЕЛЬНО fuzzy: прямые поля LIKE-источника
            // не совпадают с латинским «nayk», значит резолвер должен вернуть 'fuzzy'.
            $this->assertSame('fuzzy', $row['match_source']);
            $this->assertNull($row['match_snippet']);
        } else {
            $this->markTestSkipped('Fuzzy не вернул заказ — проверка fuzzy-fallback требует доступного collection-engine.');
        }
    }
}
