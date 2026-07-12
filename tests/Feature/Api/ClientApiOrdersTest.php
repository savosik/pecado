<?php

namespace Tests\Feature\Api;

use App\Contracts\Currency\UserCurrencyResolverInterface;
use App\Contracts\Pricing\PriceResult;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Enums\OrderType;
use App\Events\OrderCreated;
use App\Models\ApiToken;
use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ClientApiOrdersTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ApiToken $token;

    /** @var array<string, array{available: int, preorder: int}> stock по code товара */
    private array $stockMap = [];

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([OrderCreated::class]);

        $this->user = User::factory()->create();
        $this->token = ApiToken::create([
            'user_id' => $this->user->id,
            'name' => 'test',
            'is_active' => true,
        ]);

        Company::factory()->create([
            'user_id' => $this->user->id,
            'tax_id' => '7707083893',
        ]);

        // Цена — фиксированная
        $priceMock = $this->createMock(PriceServiceInterface::class);
        $priceMock->method('getPriceResult')->willReturn(new PriceResult(120.0, 100.0, 16.67, true));
        $this->app->instance(PriceServiceInterface::class, $priceMock);

        // Остатки — из stockMap по code товара
        $stockMock = $this->createMock(StockServiceInterface::class);
        $stockMock->method('getStock')->willReturnCallback(function (Product $product) {
            return $this->stockMap[$product->code] ?? ['available' => 0, 'preorder' => 0];
        });
        $this->app->instance(StockServiceInterface::class, $stockMock);

        // Валюта — базовая (null → RUB по умолчанию в контроллере)
        $currencyMock = $this->createMock(UserCurrencyResolverInterface::class);
        $currencyMock->method('resolve')->willReturn(null);
        $this->app->instance(UserCurrencyResolverInterface::class, $currencyMock);
    }

    private function product(string $code, int $available, int $preorder = 0): Product
    {
        $this->stockMap[$code] = ['available' => $available, 'preorder' => $preorder];

        return Product::factory()->create(['code' => $code, 'name' => "Товар {$code}"]);
    }

    private function order(array $products, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/client-api/{$this->token->token}/orders", array_merge([
            'inn' => '7707083893',
            'products' => $products,
        ], $extra));
    }

    public function test_fully_available_order_is_created_without_warnings(): void
    {
        $this->product('ART-A', available: 10);

        $res = $this->order([['identifier' => 'ART-A', 'quantity' => 5]]);

        $res->assertStatus(201)
            ->assertJson(['fully_fulfilled' => true, 'total_orders' => 1])
            ->assertJsonMissingPath('warnings');

        $this->assertDatabaseHas('order_items', ['product_id' => Product::first()->id, 'quantity' => 5]);
    }

    public function test_partial_stock_creates_order_for_available_and_reports_shortfall(): void
    {
        $this->product('ART-A', available: 3);

        $res = $this->order([['identifier' => 'ART-A', 'quantity' => 10]]);

        $res->assertStatus(201)
            ->assertJson(['fully_fulfilled' => false])
            ->assertJsonPath('warnings.partial.0.requested', 10)
            ->assertJsonPath('warnings.partial.0.fulfilled', 3)
            ->assertJsonPath('warnings.partial.0.shortfall', 7);

        // Заказ создан на доступное количество
        $this->assertDatabaseHas('order_items', ['product_id' => Product::first()->id, 'quantity' => 3]);
    }

    public function test_out_of_stock_item_is_skipped_but_rest_of_order_is_created(): void
    {
        $good = $this->product('ART-A', available: 5);
        $this->product('ART-B', available: 0, preorder: 0);

        $res = $this->order([
            ['identifier' => 'ART-A', 'quantity' => 2],
            ['identifier' => 'ART-B', 'quantity' => 4],
        ]);

        $res->assertStatus(201)
            ->assertJson(['fully_fulfilled' => false])
            ->assertJsonPath('warnings.unavailable.0.identifier', 'ART-B')
            ->assertJsonPath('warnings.unavailable.0.reason', 'out_of_stock');

        $this->assertDatabaseHas('order_items', ['product_id' => $good->id, 'quantity' => 2]);
        $this->assertDatabaseCount('order_items', 1);
    }

    public function test_unknown_identifier_is_skipped_but_order_still_created(): void
    {
        $good = $this->product('ART-A', available: 5);

        $res = $this->order([
            ['identifier' => 'ART-A', 'quantity' => 2],
            ['identifier' => 'GHOST-999', 'quantity' => 1],
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('warnings.unavailable.0.identifier', 'GHOST-999')
            ->assertJsonPath('warnings.unavailable.0.reason', 'not_found');

        $this->assertDatabaseHas('order_items', ['product_id' => $good->id, 'quantity' => 2]);
    }

    public function test_order_is_rejected_when_no_position_is_available(): void
    {
        $this->product('ART-B', available: 0);

        $res = $this->order([
            ['identifier' => 'ART-B', 'quantity' => 4],
            ['identifier' => 'GHOST-999', 'quantity' => 1],
        ]);

        $res->assertStatus(422)
            ->assertJsonPath('error', 'Ни одна из позиций недоступна для заказа')
            ->assertJsonCount(2, 'unavailable');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_partial_positions_are_appended_to_order_comment(): void
    {
        $this->product('ART-A', available: 3);

        $this->order([['identifier' => 'ART-A', 'quantity' => 10]], ['comment' => 'Клиентский коммент']);

        $order = Order::first();
        $this->assertStringContainsString('Клиентский коммент', $order->comment);
        $this->assertStringContainsString('[API]', $order->comment);
        $this->assertStringContainsString('не хватило 7', $order->comment);
    }

    public function test_stock_split_creates_separate_order_and_preorder(): void
    {
        $this->product('ART-A', available: 2, preorder: 5);

        $res = $this->order([['identifier' => 'ART-A', 'quantity' => 4]]);

        $res->assertStatus(201)
            ->assertJson(['fully_fulfilled' => true, 'total_orders' => 2]);

        $this->assertDatabaseHas('orders', ['type' => OrderType::ORDER->value]);
        $this->assertDatabaseHas('orders', ['type' => OrderType::PREORDER->value]);
    }

    public function test_unknown_inn_is_rejected(): void
    {
        $this->product('ART-A', available: 5);

        $res = $this->order([['identifier' => 'ART-A', 'quantity' => 1]], ['inn' => '0000000000']);

        $res->assertStatus(422)->assertJsonPath('inn', '0000000000');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_invalid_token_returns_404(): void
    {
        $this->product('ART-A', available: 5);

        $res = $this->postJson('/api/client-api/deadbeef/orders', [
            'inn' => '7707083893',
            'products' => [['identifier' => 'ART-A', 'quantity' => 1]],
        ]);

        $res->assertStatus(404);
    }

    // ─── Способ доставки (v15.3) ─────────────────────────

    public function test_pickup_order_is_created_with_null_address(): void
    {
        $this->product('ART-A', available: 10);

        $res = $this->order([['identifier' => 'ART-A', 'quantity' => 2]], [
            'delivery_method' => 'pickup',
            'address' => 'г. Москва, ул. Лишняя, д. 1',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('orders.0.delivery_method', 'pickup');

        $order = Order::first();
        $this->assertSame('pickup', $order->delivery_method->value);
        // Адрес игнорируется при самовывозе
        $this->assertNull($order->delivery_address);
    }

    public function test_order_without_delivery_method_defaults_to_delivery(): void
    {
        $this->product('ART-A', available: 10);

        $res = $this->order([['identifier' => 'ART-A', 'quantity' => 1]], [
            'address' => 'г. Москва, ул. Примерная, д. 1',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('orders.0.delivery_method', 'delivery');

        $order = Order::first();
        $this->assertSame('delivery', $order->delivery_method->value);
        $this->assertSame('г. Москва, ул. Примерная, д. 1', $order->delivery_address);
    }

    public function test_invalid_delivery_method_is_rejected(): void
    {
        $this->product('ART-A', available: 10);

        $res = $this->order([['identifier' => 'ART-A', 'quantity' => 1]], [
            'delivery_method' => 'courier',
        ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors('delivery_method');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_pickup_split_creates_both_orders_with_pickup(): void
    {
        $this->product('ART-A', available: 2, preorder: 10);

        $res = $this->order([['identifier' => 'ART-A', 'quantity' => 5]], [
            'delivery_method' => 'pickup',
        ]);

        $res->assertStatus(201)
            ->assertJson(['total_orders' => 2]);

        $this->assertSame(2, Order::count());
        foreach (Order::all() as $order) {
            $this->assertSame('pickup', $order->delivery_method->value);
            $this->assertNull($order->delivery_address);
        }
    }
}
