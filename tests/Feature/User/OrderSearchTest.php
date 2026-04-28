<?php

namespace Tests\Feature\User;

use App\Enums\OrderStatus;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->company = Company::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'ООО Пекадо',
            'tax_id' => '7707000001',
        ]);
    }

    /** @return array<int, int> */
    private function fetchOrderIds(string $query = ''): array
    {
        $url = '/cabinet/orders'.($query !== '' ? '?'.$query : '');

        $response = $this->actingAs($this->user)->get($url);
        $response->assertOk();

        // Inertia рендерит props в data-page атрибуте корневого <div>. В тестах используем
        // полный HTML-ответ (без X-Inertia заголовка), чтобы обойти 409 от version-check.
        $content = $response->getContent();
        if (! preg_match('/data-page="([^"]+)"/', $content, $matches)) {
            $this->fail('Не удалось извлечь data-page из HTML-ответа');
        }
        $page = json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5), true);
        $rows = $page['props']['orders']['data'] ?? [];

        return array_map(static fn (array $row) => (int) $row['id'], $rows);
    }

    private function makeOrder(array $attributes = []): Order
    {
        return Order::factory()->create(array_merge([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
        ], $attributes));
    }

    private function attachItem(Order $order, Product $product, int $quantity = 1): OrderItem
    {
        return OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'price' => 100,
            'quantity' => $quantity,
            'subtotal' => 100 * $quantity,
        ]);
    }

    #[Test]
    public function partial_erp_number_finds_order(): void
    {
        $order = $this->makeOrder(['erp_number' => '29УТ-003413']);
        $this->makeOrder(['erp_number' => '29УТ-999999']);

        $this->assertContains($order->id, $this->fetchOrderIds('search=003413'));
    }

    #[Test]
    public function normalized_erp_number_finds_order_typed_without_dash(): void
    {
        $order = $this->makeOrder(['erp_number' => '29УТ-003413']);

        $ids = $this->fetchOrderIds('search=29%D0%A3%D0%A2003413');

        $this->assertContains($order->id, $ids);
    }

    #[Test]
    public function exact_erp_number_finds_order(): void
    {
        $order = $this->makeOrder(['erp_number' => '29УТ-003413']);

        $ids = $this->fetchOrderIds('search='.urlencode('29УТ-003413'));

        $this->assertContains($order->id, $ids);
    }

    #[Test]
    public function search_by_product_name_in_items_finds_order(): void
    {
        $product = Product::factory()->create(['name' => 'Кроссовки Nike Air Max']);
        $other = Product::factory()->create(['name' => 'Кофта обычная']);

        $matching = $this->makeOrder(['erp_number' => 'NUM-001']);
        $this->attachItem($matching, $product);

        $unrelated = $this->makeOrder(['erp_number' => 'NUM-002']);
        $this->attachItem($unrelated, $other);

        $ids = $this->fetchOrderIds('search='.urlencode('Кроссовки'));

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
    }

    #[Test]
    public function search_by_brand_in_items_finds_order(): void
    {
        $brand = Brand::create(['name' => 'Adidas', 'slug' => 'adidas-test']);
        $product = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'Adidas Boost']);
        $other = Product::factory()->create(['name' => 'Без бренда']);

        $matching = $this->makeOrder(['erp_number' => 'BR-001']);
        $this->attachItem($matching, $product);
        $unrelated = $this->makeOrder(['erp_number' => 'BR-002']);
        $this->attachItem($unrelated, $other);

        $ids = $this->fetchOrderIds('search=Adidas');

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
    }

    #[Test]
    public function search_by_sku_in_items_finds_order(): void
    {
        $product = Product::factory()->create(['name' => 'Some product', 'sku' => 'AM90-001']);
        $matching = $this->makeOrder(['erp_number' => 'SKU-001']);
        $this->attachItem($matching, $product);

        $unrelated = $this->makeOrder(['erp_number' => 'SKU-002']);

        $ids = $this->fetchOrderIds('search=AM90-001');

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
    }

    #[Test]
    public function search_by_barcode_finds_order_with_exact_match(): void
    {
        $product = Product::factory()->create(['name' => 'Товар со штрихкодом']);
        ProductBarcode::create(['product_id' => $product->id, 'barcode' => '4607123456789']);
        $matching = $this->makeOrder(['erp_number' => 'BC-001']);
        $this->attachItem($matching, $product);

        $other = Product::factory()->create();
        ProductBarcode::create(['product_id' => $other->id, 'barcode' => '4607000000001']);
        $unrelated = $this->makeOrder(['erp_number' => 'BC-002']);
        $this->attachItem($unrelated, $other);

        $ids = $this->fetchOrderIds('search=4607123456789');

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
    }

    #[Test]
    public function search_by_company_name_respects_user_scope(): void
    {
        $matching = $this->makeOrder(['erp_number' => 'CO-001']);

        $otherUser = User::factory()->create();
        $foreignCompany = Company::factory()->create([
            'user_id' => $otherUser->id,
            'name' => 'ООО Пекадо',
        ]);
        $foreignOrder = Order::factory()->create([
            'user_id' => $otherUser->id,
            'company_id' => $foreignCompany->id,
            'erp_number' => 'FOREIGN-001',
        ]);

        $ids = $this->fetchOrderIds('search='.urlencode('Пекадо'));

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($foreignOrder->id, $ids);
    }

    #[Test]
    public function search_by_tax_id_finds_only_own_orders(): void
    {
        $matching = $this->makeOrder(['erp_number' => 'TAX-001']);

        $otherUser = User::factory()->create();
        $foreignCompany = Company::factory()->create([
            'user_id' => $otherUser->id,
            'tax_id' => '7707000001',
        ]);
        $foreignOrder = Order::factory()->create([
            'user_id' => $otherUser->id,
            'company_id' => $foreignCompany->id,
            'erp_number' => 'TAX-FOREIGN',
        ]);

        $ids = $this->fetchOrderIds('search=7707000001');

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($foreignOrder->id, $ids);
    }

    #[Test]
    public function search_by_comment_finds_order(): void
    {
        $matching = $this->makeOrder([
            'erp_number' => 'COM-001',
            'comment' => 'Привезти к 17:00 на склад',
        ]);
        $unrelated = $this->makeOrder(['erp_number' => 'COM-002', 'comment' => 'Стандартная доставка']);

        $ids = $this->fetchOrderIds('search='.urlencode('17:00 на склад'));

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
    }

    #[Test]
    public function search_does_not_leak_orders_of_other_users(): void
    {
        $product = Product::factory()->create(['name' => 'Уникальный товар XYZ']);
        $myOrder = $this->makeOrder(['erp_number' => 'MINE']);
        $this->attachItem($myOrder, $product);

        $otherUser = User::factory()->create();
        $foreignCompany = Company::factory()->create(['user_id' => $otherUser->id]);
        $foreignOrder = Order::factory()->create([
            'user_id' => $otherUser->id,
            'company_id' => $foreignCompany->id,
            'erp_number' => 'FOREIGN',
        ]);
        $this->attachItem($foreignOrder, $product);

        $ids = $this->fetchOrderIds('search='.urlencode('Уникальный товар XYZ'));

        $this->assertContains($myOrder->id, $ids);
        $this->assertNotContains($foreignOrder->id, $ids);
    }

    #[Test]
    public function multiple_match_sources_return_order_only_once(): void
    {
        $product = Product::factory()->create(['name' => 'Кроссовки 003413']);
        $order = $this->makeOrder(['erp_number' => '29УТ-003413']);
        $this->attachItem($order, $product);

        $ids = $this->fetchOrderIds('search=003413');

        $this->assertSame(1, count(array_filter($ids, fn ($id) => $id === $order->id)));
    }

    #[Test]
    public function product_id_filter_returns_only_orders_with_that_product(): void
    {
        $product = Product::factory()->create();
        $other = Product::factory()->create();

        $matching = $this->makeOrder(['erp_number' => 'PID-001']);
        $this->attachItem($matching, $product);
        $unrelated = $this->makeOrder(['erp_number' => 'PID-002']);
        $this->attachItem($unrelated, $other);

        $ids = $this->fetchOrderIds('product_id='.$product->id);

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
    }

    #[Test]
    public function status_array_filter_supports_multi_select(): void
    {
        $pending = $this->makeOrder(['erp_number' => 'ST-P', 'status' => OrderStatus::PENDING]);
        $confirmed = $this->makeOrder(['erp_number' => 'ST-C', 'status' => OrderStatus::CONFIRMED]);
        $closed = $this->makeOrder(['erp_number' => 'ST-X', 'status' => OrderStatus::CLOSED]);

        $ids = $this->fetchOrderIds('status[]=pending&status[]=confirmed');

        $this->assertContains($pending->id, $ids);
        $this->assertContains($confirmed->id, $ids);
        $this->assertNotContains($closed->id, $ids);
    }

    #[Test]
    public function items_count_range_filter_works(): void
    {
        $small = $this->makeOrder(['erp_number' => 'IC-1']);
        $this->attachItem($small, Product::factory()->create());

        $large = $this->makeOrder(['erp_number' => 'IC-5']);
        for ($i = 0; $i < 5; $i++) {
            $this->attachItem($large, Product::factory()->create());
        }

        $ids = $this->fetchOrderIds('items_count_from=3');

        $this->assertContains($large->id, $ids);
        $this->assertNotContains($small->id, $ids);
    }

    #[Test]
    public function brand_ids_filter_returns_orders_with_brand_in_items(): void
    {
        $adidas = Brand::create(['name' => 'Adidas BF', 'slug' => 'adidas-bf-'.Str::random(5)]);
        $nike = Brand::create(['name' => 'Nike BF', 'slug' => 'nike-bf-'.Str::random(5)]);

        $adidasProduct = Product::factory()->create(['brand_id' => $adidas->id]);
        $nikeProduct = Product::factory()->create(['brand_id' => $nike->id]);

        $orderA = $this->makeOrder(['erp_number' => 'BR-A']);
        $this->attachItem($orderA, $adidasProduct);
        $orderN = $this->makeOrder(['erp_number' => 'BR-N']);
        $this->attachItem($orderN, $nikeProduct);

        $ids = $this->fetchOrderIds('brand_ids[]='.$adidas->id);

        $this->assertContains($orderA->id, $ids);
        $this->assertNotContains($orderN->id, $ids);
    }
}
