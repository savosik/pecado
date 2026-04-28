<?php

namespace Tests\Feature\User;

use App\Enums\ReturnReason;
use App\Enums\ReturnStatus;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductReturn;
use App\Models\ReturnItem;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReturnShipmentPickerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function search(string $query = ''): array
    {
        $url = '/cabinet/returns/search-shipments'.($query !== '' ? '?'.$query : '');
        $response = $this->actingAs($this->user)->getJson($url);
        $response->assertOk();

        return $response->json();
    }

    private function makeShipmentWithItem(array $shipmentAttrs = [], ?Product $product = null): array
    {
        $shipment = Shipment::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'number' => 'SH-'.Str::random(6),
            'user_id' => $this->user->id,
            'date' => now(),
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => 1000,
        ], $shipmentAttrs));

        $product ??= Product::factory()->create();
        $item = ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => $product->id,
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
        [$shipment] = $this->makeShipmentWithItem(['number' => '29УТ-003413']);
        $this->makeShipmentWithItem(['number' => '29УТ-999999']);

        $ids = array_column($this->search('query=003413'), 'id');

        $this->assertContains($shipment->id, $ids);
    }

    #[Test]
    public function normalized_number_finds_shipment_typed_without_dash(): void
    {
        [$shipment] = $this->makeShipmentWithItem(['number' => '29УТ-003413']);

        $ids = array_column($this->search('query='.urlencode('29УТ003413')), 'id');

        $this->assertContains($shipment->id, $ids);
    }

    #[Test]
    public function search_by_product_name_in_items(): void
    {
        $product = Product::factory()->create(['name' => 'Кроссовки Air Max']);
        [$matching] = $this->makeShipmentWithItem([], $product);

        $other = Product::factory()->create(['name' => 'Прочая обувь']);
        [$unrelated] = $this->makeShipmentWithItem([], $other);

        $rows = $this->search('query='.urlencode('Air Max'));
        $ids = array_column($rows, 'id');

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);

        $matched = collect($rows)->firstWhere('id', $matching->id);
        $this->assertSame('composition', $matched['match_source']);
        $this->assertSame($product->id, $matched['match_product']['id']);
    }

    #[Test]
    public function search_by_sku_in_items(): void
    {
        $product = Product::factory()->create(['sku' => 'AM-001']);
        [$matching] = $this->makeShipmentWithItem([], $product);

        $other = Product::factory()->create(['sku' => 'OTHER-999']);
        [$unrelated] = $this->makeShipmentWithItem([], $other);

        $ids = array_column($this->search('query=AM-001'), 'id');

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
    }

    #[Test]
    public function search_by_brand_in_items(): void
    {
        $brand = Brand::create(['name' => 'AdidasPick', 'slug' => 'adidas-pick-'.Str::random(5)]);
        $product = Product::factory()->create(['brand_id' => $brand->id]);
        [$matching] = $this->makeShipmentWithItem([], $product);
        [$unrelated] = $this->makeShipmentWithItem();

        $ids = array_column($this->search('query=AdidasPick'), 'id');

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
    }

    #[Test]
    public function search_by_barcode_exact_match(): void
    {
        $product = Product::factory()->create();
        ProductBarcode::create(['product_id' => $product->id, 'barcode' => '4607123456789']);
        [$matching] = $this->makeShipmentWithItem([], $product);
        [$unrelated] = $this->makeShipmentWithItem();

        $ids = array_column($this->search('query=4607123456789'), 'id');

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
    }

    #[Test]
    public function open_returns_count_includes_pending_and_confirmed_only(): void
    {
        [$shipment, $shipmentItem, $product] = $this->makeShipmentWithItem();

        $pendingReturn = ProductReturn::factory()->create([
            'user_id' => $this->user->id,
            'status' => ReturnStatus::PENDING,
        ]);
        ReturnItem::factory()->create([
            'return_id' => $pendingReturn->id,
            'shipment_id' => $shipment->id,
            'shipment_item_id' => $shipmentItem->id,
            'product_id' => $product->id,
            'reason' => ReturnReason::DEFECTIVE,
        ]);

        $confirmedReturn = ProductReturn::factory()->create([
            'user_id' => $this->user->id,
            'status' => ReturnStatus::CONFIRMED,
        ]);
        ReturnItem::factory()->create([
            'return_id' => $confirmedReturn->id,
            'shipment_id' => $shipment->id,
            'shipment_item_id' => $shipmentItem->id,
            'product_id' => $product->id,
            'reason' => ReturnReason::DEFECTIVE,
        ]);

        // Закрытый и отменённый — не считаются.
        $closedReturn = ProductReturn::factory()->create([
            'user_id' => $this->user->id,
            'status' => ReturnStatus::CLOSED,
        ]);
        ReturnItem::factory()->create([
            'return_id' => $closedReturn->id,
            'shipment_id' => $shipment->id,
            'shipment_item_id' => $shipmentItem->id,
            'product_id' => $product->id,
            'reason' => ReturnReason::DEFECTIVE,
        ]);

        $cancelledReturn = ProductReturn::factory()->create([
            'user_id' => $this->user->id,
            'status' => ReturnStatus::CANCELLED,
        ]);
        ReturnItem::factory()->create([
            'return_id' => $cancelledReturn->id,
            'shipment_id' => $shipment->id,
            'shipment_item_id' => $shipmentItem->id,
            'product_id' => $product->id,
            'reason' => ReturnReason::DEFECTIVE,
        ]);

        $rows = $this->search('query='.urlencode($shipment->number));
        $row = collect($rows)->firstWhere('id', $shipment->id);

        $this->assertSame(2, $row['open_returns_count']);
    }

    #[Test]
    public function open_returns_count_zero_when_no_returns(): void
    {
        [$shipment] = $this->makeShipmentWithItem();

        $rows = $this->search('query='.urlencode($shipment->number));
        $row = collect($rows)->firstWhere('id', $shipment->id);

        $this->assertSame(0, $row['open_returns_count']);
    }

    #[Test]
    public function scope_excludes_other_users_shipments(): void
    {
        [$mine] = $this->makeShipmentWithItem();

        $other = User::factory()->create();
        $foreign = Shipment::create([
            'uuid' => (string) Str::uuid(),
            'number' => 'SH-FOREIGN',
            'user_id' => $other->id,
            'date' => now(),
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => 100,
        ]);

        $ids = array_column($this->search('query=SH'), 'id');

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
    }

    #[Test]
    public function limit_20_results(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->makeShipmentWithItem(['number' => 'BULK-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT)]);
        }

        $rows = $this->search('query=BULK');

        $this->assertCount(20, $rows);
    }

    #[Test]
    public function deduplication_one_shipment_one_row(): void
    {
        $product = Product::factory()->create(['name' => 'Уникальный товар Air']);
        [$shipment, $item] = $this->makeShipmentWithItem(['number' => 'AIR-DEDUP'], $product);

        // Несколько items в одной отгрузке с тем же товаром (повторное появление product в составе).
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'price' => 100,
            'auto_discount_percent' => 0,
            'manual_discount_percent' => 0,
            'total' => 300,
            'subtotal' => 300,
            'vat_rate' => 20,
        ]);

        $rows = $this->search('query='.urlencode('Air'));
        $matches = array_filter($rows, fn ($r) => $r['id'] === $shipment->id);

        $this->assertCount(1, $matches);
    }

    #[Test]
    public function match_source_number_for_direct_number_hit(): void
    {
        [$shipment] = $this->makeShipmentWithItem(['number' => 'SH-PICK-001']);

        $rows = $this->search('query=SH-PICK');
        $row = collect($rows)->firstWhere('id', $shipment->id);

        $this->assertSame('number', $row['match_source']);
        $this->assertNull($row['match_product']);
    }
}
