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

class ReturnSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /** @return array<int, int> */
    private function fetchReturnIds(string $query = ''): array
    {
        $url = '/cabinet/returns'.($query !== '' ? '?'.$query : '');

        $response = $this->actingAs($this->user)->get($url);
        $response->assertOk();

        if (! preg_match('/data-page="([^"]+)"/', $response->getContent(), $matches)) {
            $this->fail('Не удалось извлечь data-page из HTML-ответа');
        }
        $page = json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5), true);
        $rows = $page['props']['returns']['data'] ?? [];

        return array_map(static fn (array $row) => (int) $row['id'], $rows);
    }

    private function makeReturn(array $attrs = []): ProductReturn
    {
        return ProductReturn::factory()->create(array_merge([
            'user_id' => $this->user->id,
        ], $attrs));
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

    private function attachReturnItem(ProductReturn $return, array $attrs = []): ReturnItem
    {
        // Колонка return_items.shipment_id NOT NULL (миграция 2026_04_21).
        // Если в attrs нет привязки к реализации — создаём фоновую.
        if (! isset($attrs['shipment_item_id'])) {
            [$shipment, $shipmentItem] = $this->makeShipmentWithItem();
            $attrs['shipment_item_id'] = $shipmentItem->id;
            $attrs['shipment_id'] = $shipment->id;
            $attrs['product_id'] ??= $shipmentItem->product_id;
        } elseif (! isset($attrs['shipment_id'])) {
            $attrs['shipment_id'] = ShipmentItem::find($attrs['shipment_item_id'])?->shipment_id;
        }

        return ReturnItem::factory()->create(array_merge([
            'return_id' => $return->id,
            'reason' => ReturnReason::DEFECTIVE,
        ], $attrs));
    }

    #[Test]
    public function partial_erp_number_finds_return(): void
    {
        $r = $this->makeReturn(['erp_number' => '29В-001245']);
        $this->makeReturn(['erp_number' => '29В-999999']);

        $this->assertContains($r->id, $this->fetchReturnIds('search=001245'));
    }

    #[Test]
    public function normalized_erp_number_finds_return_typed_without_dash(): void
    {
        $r = $this->makeReturn(['erp_number' => '29В-001245']);

        $ids = $this->fetchReturnIds('search='.urlencode('29В001245'));

        $this->assertContains($r->id, $ids);
    }

    #[Test]
    public function search_by_source_shipment_number_finds_return(): void
    {
        [$shipment, $shipmentItem] = $this->makeShipmentWithItem(['number' => '29УТ-003413']);
        $matching = $this->makeReturn(['erp_number' => 'RET-001']);
        $this->attachReturnItem($matching, [
            'shipment_item_id' => $shipmentItem->id,
            'product_id' => $shipmentItem->product_id,
        ]);

        $unrelated = $this->makeReturn(['erp_number' => 'RET-002']);

        $ids = $this->fetchReturnIds('search=003413');

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
    }

    #[Test]
    public function search_by_product_name_in_items_finds_return(): void
    {
        $product = Product::factory()->create(['name' => 'Кроссовки Air Max']);
        [$_, $shipmentItem] = $this->makeShipmentWithItem([], $product);
        $matching = $this->makeReturn(['erp_number' => 'PROD-001']);
        $this->attachReturnItem($matching, [
            'shipment_item_id' => $shipmentItem->id,
            'product_id' => $product->id,
        ]);

        $unrelated = $this->makeReturn(['erp_number' => 'PROD-002']);

        $ids = $this->fetchReturnIds('search='.urlencode('Кроссовки'));

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
    }

    #[Test]
    public function search_by_brand_in_items_finds_return(): void
    {
        $brand = Brand::create(['name' => 'Adidas RT', 'slug' => 'adidas-rt-'.Str::random(5)]);
        $product = Product::factory()->create(['brand_id' => $brand->id]);
        [$_, $shipmentItem] = $this->makeShipmentWithItem([], $product);
        $matching = $this->makeReturn(['erp_number' => 'BR-001']);
        $this->attachReturnItem($matching, [
            'shipment_item_id' => $shipmentItem->id,
            'product_id' => $product->id,
        ]);

        $unrelated = $this->makeReturn(['erp_number' => 'BR-002']);

        $ids = $this->fetchReturnIds('search=Adidas');

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
    }

    #[Test]
    public function search_by_barcode_finds_return_with_exact_match(): void
    {
        $product = Product::factory()->create();
        ProductBarcode::create(['product_id' => $product->id, 'barcode' => '4607123456789']);
        [$_, $shipmentItem] = $this->makeShipmentWithItem([], $product);
        $matching = $this->makeReturn(['erp_number' => 'BC-001']);
        $this->attachReturnItem($matching, [
            'shipment_item_id' => $shipmentItem->id,
            'product_id' => $product->id,
        ]);

        $unrelated = $this->makeReturn(['erp_number' => 'BC-002']);

        $ids = $this->fetchReturnIds('search=4607123456789');

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
    }

    #[Test]
    public function search_by_reason_comment_finds_return(): void
    {
        $matching = $this->makeReturn(['erp_number' => 'RC-001']);
        $this->attachReturnItem($matching, [
            'reason_comment' => 'Упаковка повреждена при доставке',
        ]);

        $unrelated = $this->makeReturn(['erp_number' => 'RC-002']);
        $this->attachReturnItem($unrelated, ['reason_comment' => 'Без замечаний']);

        // SQLite LIKE кириллицей case-sensitive (в MySQL utf8mb4_unicode_ci — нет).
        // Тест использует совпадающий регистр; реальный пользовательский кейс закрывает MySQL collation.
        $ids = $this->fetchReturnIds('search='.urlencode('Упаковка повреждена'));

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
    }

    #[Test]
    public function multi_select_reason_filter_returns_matching(): void
    {
        $defective = $this->makeReturn(['erp_number' => 'RR-D']);
        $this->attachReturnItem($defective, ['reason' => ReturnReason::DEFECTIVE]);

        $wrongSize = $this->makeReturn(['erp_number' => 'RR-W']);
        $this->attachReturnItem($wrongSize, ['reason' => ReturnReason::WRONG_SIZE]);

        $changedMind = $this->makeReturn(['erp_number' => 'RR-C']);
        $this->attachReturnItem($changedMind, ['reason' => ReturnReason::CHANGED_MIND]);

        $ids = $this->fetchReturnIds('reason[]=defective&reason[]=wrong_size');

        $this->assertContains($defective->id, $ids);
        $this->assertContains($wrongSize->id, $ids);
        $this->assertNotContains($changedMind->id, $ids);
    }

    #[Test]
    public function status_array_filter_supports_multi_select(): void
    {
        $pending = $this->makeReturn(['erp_number' => 'ST-P', 'status' => ReturnStatus::PENDING_APPROVAL]);
        $confirmed = $this->makeReturn(['erp_number' => 'ST-C', 'status' => ReturnStatus::IN_RESERVE]);
        $cancelled = $this->makeReturn(['erp_number' => 'ST-X', 'status' => ReturnStatus::REJECTED]);

        $ids = $this->fetchReturnIds('status[]=pending_approval&status[]=in_reserve');

        $this->assertContains($pending->id, $ids);
        $this->assertContains($confirmed->id, $ids);
        $this->assertNotContains($cancelled->id, $ids);
    }

    #[Test]
    public function search_does_not_leak_returns_of_other_users(): void
    {
        $product = Product::factory()->create(['name' => 'Уникальная позиция возврата ZZZ']);
        [$_, $shipmentItem] = $this->makeShipmentWithItem([], $product);
        $mine = $this->makeReturn(['erp_number' => 'MINE-RET']);
        $this->attachReturnItem($mine, [
            'shipment_item_id' => $shipmentItem->id,
            'product_id' => $product->id,
        ]);

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
        $foreignShipmentItem = ShipmentItem::create([
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
        $foreignReturn = ProductReturn::factory()->create(['user_id' => $other->id, 'erp_number' => 'FOREIGN']);
        ReturnItem::factory()->create([
            'return_id' => $foreignReturn->id,
            'shipment_id' => $foreignShipment->id,
            'shipment_item_id' => $foreignShipmentItem->id,
            'product_id' => $product->id,
            'reason' => ReturnReason::DEFECTIVE,
        ]);

        $ids = $this->fetchReturnIds('search='.urlencode('Уникальная позиция возврата ZZZ'));

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($foreignReturn->id, $ids);
    }
}
