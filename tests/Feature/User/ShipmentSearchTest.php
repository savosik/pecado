<?php

namespace Tests\Feature\User;

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShipmentSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /** @return array<int, int> */
    private function fetchShipmentIds(string $query = ''): array
    {
        $url = '/cabinet/shipments'.($query !== '' ? '?'.$query : '');

        $response = $this->actingAs($this->user)->get($url);
        $response->assertOk();

        if (! preg_match('/data-page="([^"]+)"/', $response->getContent(), $matches)) {
            $this->fail('Не удалось извлечь data-page из HTML-ответа');
        }
        $page = json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5), true);
        $rows = $page['props']['shipments']['data'] ?? [];

        return array_map(static fn (array $row) => (int) $row['id'], $rows);
    }

    private function makeShipment(array $attrs = [], ?Product $product = null, ?string $orderUuid = null): array
    {
        $shipment = Shipment::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'number' => 'SH-'.Str::random(6),
            'user_id' => $this->user->id,
            'date' => now(),
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => 1000,
        ], $attrs));

        $product ??= Product::factory()->create();
        $item = ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => $product->id,
            'order_uuid' => $orderUuid,
            'quantity' => 5,
            'price' => 200,
            'auto_discount_percent' => 0,
            'manual_discount_percent' => 0,
            'total' => 1000,
            'subtotal' => 1000,
            'vat_rate' => 20,
        ]);

        return [$shipment, $item, $product];
    }

    #[Test]
    public function partial_number_finds_shipment(): void
    {
        [$shipment] = $this->makeShipment(['number' => '29УТ-003413']);
        $this->makeShipment(['number' => '29УТ-999999']);

        $this->assertContains($shipment->id, $this->fetchShipmentIds('search=003413'));
    }

    #[Test]
    public function normalized_number_finds_shipment_typed_without_dash(): void
    {
        [$shipment] = $this->makeShipment(['number' => '29УТ-003413']);

        $this->assertContains($shipment->id, $this->fetchShipmentIds('search='.urlencode('29УТ003413')));
    }

    #[Test]
    public function search_by_brand_in_items_finds_shipment(): void
    {
        $brand = Brand::create(['name' => 'Adidas SH', 'slug' => 'adidas-sh-'.Str::random(5)]);
        $product = Product::factory()->create(['brand_id' => $brand->id]);
        [$matching] = $this->makeShipment([], $product);
        [$unrelated] = $this->makeShipment();

        $ids = $this->fetchShipmentIds('search=Adidas');

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
    }

    #[Test]
    public function search_by_barcode_in_items_finds_shipment(): void
    {
        $product = Product::factory()->create();
        ProductBarcode::create(['product_id' => $product->id, 'barcode' => '4607123456789']);
        [$matching] = $this->makeShipment([], $product);
        [$unrelated] = $this->makeShipment();

        $ids = $this->fetchShipmentIds('search=4607123456789');

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
    }

    #[Test]
    public function search_by_product_code_in_items_finds_shipment(): void
    {
        $product = Product::factory()->create(['code' => '00012345']);
        [$matching] = $this->makeShipment([], $product);

        $other = Product::factory()->create(['code' => '99999999']);
        [$unrelated] = $this->makeShipment([], $other);

        $ids = $this->fetchShipmentIds('search=00012345');

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
    }

    #[Test]
    public function order_uuid_filter_returns_only_shipments_of_that_order(): void
    {
        $orderUuid = (string) Str::uuid();
        [$matching] = $this->makeShipment([], null, $orderUuid);
        [$unrelated] = $this->makeShipment([], null, (string) Str::uuid());

        $ids = $this->fetchShipmentIds('order_uuid='.$orderUuid);

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
    }

    #[Test]
    public function brand_ids_filter_returns_shipments_with_brand_in_items(): void
    {
        $adidas = Brand::create(['name' => 'Adidas BF SH', 'slug' => 'adidas-bf-sh-'.Str::random(5)]);
        $nike = Brand::create(['name' => 'Nike BF SH', 'slug' => 'nike-bf-sh-'.Str::random(5)]);

        [$adidasShipment] = $this->makeShipment([], Product::factory()->create(['brand_id' => $adidas->id]));
        [$nikeShipment] = $this->makeShipment([], Product::factory()->create(['brand_id' => $nike->id]));

        $ids = $this->fetchShipmentIds('brand_ids[]='.$adidas->id);

        $this->assertContains($adidasShipment->id, $ids);
        $this->assertNotContains($nikeShipment->id, $ids);
    }

    #[Test]
    public function status_array_filter_supports_multi_select(): void
    {
        [$completed] = $this->makeShipment(['status' => 'completed']);
        [$inProgress] = $this->makeShipment(['status' => 'in_progress']);
        [$cancelled] = $this->makeShipment(['status' => 'cancelled']);

        $ids = $this->fetchShipmentIds('status[]=completed&status[]=in_progress');

        $this->assertContains($completed->id, $ids);
        $this->assertContains($inProgress->id, $ids);
        $this->assertNotContains($cancelled->id, $ids);
    }

    #[Test]
    public function search_does_not_leak_shipments_of_other_users(): void
    {
        $product = Product::factory()->create(['name' => 'Уникальная отгрузка ABC']);
        [$mine] = $this->makeShipment([], $product);

        $other = User::factory()->create();
        $foreignShipment = Shipment::create([
            'uuid' => (string) Str::uuid(),
            'number' => 'SH-FOREIGN',
            'user_id' => $other->id,
            'date' => now(),
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => 500,
        ]);
        ShipmentItem::create([
            'shipment_id' => $foreignShipment->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 100,
            'auto_discount_percent' => 0,
            'manual_discount_percent' => 0,
            'total' => 100,
            'subtotal' => 100,
            'vat_rate' => 20,
        ]);

        $ids = $this->fetchShipmentIds('search='.urlencode('Уникальная отгрузка ABC'));

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($foreignShipment->id, $ids);
    }
}
