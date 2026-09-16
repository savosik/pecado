<?php

namespace Tests\Feature\Api\Client;

use App\Contracts\Currency\UserCurrencyResolverInterface;
use App\Contracts\Pricing\PriceResult;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Enums\OrderStatus;
use App\Jobs\PublishOrderToErpJob;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class ClientApiOrdersWriteTest extends ClientApiTestCase
{
    /** @var array<string, array{available: int, preorder: int}> */
    private array $stockMap = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([PublishOrderToErpJob::class]);

        $prices = $this->createMock(PriceServiceInterface::class);
        $prices->method('getPriceResult')->willReturn(new PriceResult(120.0, 100.0, 16.67, true));
        $this->app->instance(PriceServiceInterface::class, $prices);

        $stocks = $this->createMock(StockServiceInterface::class);
        $stocks->method('getStock')->willReturnCallback(fn (Product $p) => $this->stockMap[$p->code] ?? ['available' => 0, 'preorder' => 0]);
        $this->app->instance(StockServiceInterface::class, $stocks);

        $currency = $this->createMock(UserCurrencyResolverInterface::class);
        $currency->method('resolve')->willReturn(null);
        $this->app->instance(UserCurrencyResolverInterface::class, $currency);
    }

    private function product(string $code, int $available, int $preorder = 0): Product
    {
        $this->stockMap[$code] = ['available' => $available, 'preorder' => $preorder];

        return Product::factory()->create(['code' => $code, 'name' => 'Товар '.$code]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @param  array<string, mixed>  $extra
     */
    private function create(array $products, array $extra = [], ?string $key = 'k-1')
    {
        return $this->api('POST', '/orders', array_merge(['products' => $products], $extra), $key ? ['Idempotency-Key' => $key] : []);
    }

    #[Test]
    #[TestDox('Заказ создаётся на основную компанию, наличие и предзаказ — отдельными заказами, недостача в meta')]
    public function creates_orders_with_friendly_shortfall(): void
    {
        $this->product('A', available: 10);
        $this->product('B', available: 2, preorder: 3);
        $this->product('C', available: 0);

        $response = $this->create([
            ['identifier' => 'A', 'quantity' => 5],
            ['identifier' => 'B', 'quantity' => 10],
            ['identifier' => 'C', 'quantity' => 1],
            ['identifier' => 'NOPE', 'quantity' => 1],
        ], ['comment' => 'Срочно'])->assertStatus(201);

        $response->assertJsonPath('meta.created', true)
            ->assertJsonPath('meta.total_orders', 2)
            ->assertJsonPath('meta.fully_fulfilled', false)
            ->assertJsonCount(2, 'meta.not_accepted')
            ->assertJsonPath('meta.partial.0.identifier', 'B')
            ->assertJsonPath('meta.partial.0.fulfilled', 5)
            ->assertJsonPath('data.0.type', 'order')
            ->assertJsonPath('data.1.type', 'preorder');

        $order = Order::where('type', 'order')->first();
        $this->assertSame($this->company->id, $order->company_id);
        $this->assertStringContainsString('[API] Заказ принят не в полном объёме', $order->comment);
        Queue::assertPushed(PublishOrderToErpJob::class, 2);
    }

    #[Test]
    #[TestDox('Повтор с тем же Idempotency-Key не создаёт второй заказ и не публикует в шину дважды; без ключа — 422')]
    public function creation_is_idempotent(): void
    {
        $this->product('A', available: 10);
        $payload = [['identifier' => 'A', 'quantity' => 1]];

        $first = $this->create($payload, key: 'same')->assertStatus(201);
        $second = $this->create($payload, key: 'same')->assertStatus(201);

        $this->assertSame($first->json('data.0.order_id'), $second->json('data.0.order_id'));
        $this->assertTrue($second->json('meta.idempotent_replay'));
        $this->assertSame(1, Order::count());
        Queue::assertPushed(PublishOrderToErpJob::class, 1);

        $this->create($payload, key: null)->assertStatus(422)->assertJsonPath('errors.0.code', 'idempotency_key_required');
        $this->create([['identifier' => 'A', 'quantity' => 2]], key: 'same')->assertStatus(422)->assertJsonPath('errors.0.code', 'idempotency_key_reused');
    }

    #[Test]
    #[TestDox('Юрлицо: при нескольких без основной — company_required с перечнем; чужая компания — 404; ИНН работает')]
    public function company_context(): void
    {
        $this->product('A', available: 10);
        $this->company->update(['is_default' => false]);
        $second = Company::factory()->create(['user_id' => $this->client->id, 'tax_id' => '7727563778']);

        $this->create([['identifier' => 'A', 'quantity' => 1]], key: 'c-1')->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'company_required')
            ->assertJsonCount(2, 'meta.companies');

        $this->create([['identifier' => 'A', 'quantity' => 1]], ['inn' => '7727563778'], 'c-2')->assertStatus(201);
        $this->assertSame($second->id, Order::first()->company_id);

        $foreign = Company::factory()->create(['user_id' => User::factory()->create()->id, 'tax_id' => '5000000000']);
        $this->create([['identifier' => 'A', 'quantity' => 1]], ['company_id' => $foreign->id], 'c-3')->assertStatus(404);
    }

    #[Test]
    #[TestDox('Нечего отгружать — 422 nothing_to_place с причинами, ключ освобождается')]
    public function nothing_to_place(): void
    {
        $this->product('C', available: 0);

        $this->create([['identifier' => 'C', 'quantity' => 1]], key: 'n-1')->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'nothing_to_place')
            ->assertJsonPath('meta.not_accepted.0.reason', 'out_of_stock');

        $this->assertDatabaseCount('client_api_idempotency_keys', 0);
        $this->assertSame(0, Order::count());
    }

    #[Test]
    #[TestDox('Резерв: вне режима 403 reserve_unavailable; в режиме заказ создаётся с reserved_until')]
    public function reserve_flag_is_gated(): void
    {
        $this->product('A', available: 10);
        config(['order_reserve.enabled' => false]);

        $this->create([['identifier' => 'A', 'quantity' => 1]], ['reserve' => true], 'r-1')->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'reserve_unavailable');

        config(['order_reserve.enabled' => true, 'order_reserve.canary' => '']);
        $this->client->update(['reserve_allowed' => true]);

        $this->create([['identifier' => 'A', 'quantity' => 1]], ['reserve' => true], 'r-2')->assertStatus(201)
            ->assertJsonPath('data.0.reserve', true)
            ->assertJsonStructure(['data' => [['reserved_until']]]);
    }

    #[Test]
    #[TestDox('Отмена: гейт режима, not_cancellable для собранного, отмена резерва пишет комментарий канала')]
    public function cancel_order(): void
    {
        config(['order_reserve.enabled' => false]);
        $order = Order::factory()->create(['user_id' => $this->client->id, 'status' => OrderStatus::PENDING_APPROVAL]);

        $this->api('POST', "/orders/{$order->id}/cancel")->assertStatus(403)->assertJsonPath('errors.0.code', 'order_cancel_unavailable');

        config(['order_reserve.enabled' => true]);
        $shipped = Order::factory()->create(['user_id' => $this->client->id, 'status' => OrderStatus::SHIPPING]);
        $this->api('POST', "/orders/{$shipped->id}/cancel")->assertStatus(422)->assertJsonPath('errors.0.code', 'not_cancellable');

        $this->api('POST', "/orders/{$order->id}/cancel")->assertOk()->assertJsonPath('data.cancelled', true);
        $this->assertSoftDeleted('orders', ['id' => $order->id]);
        $history = $order->statusHistories()->latest('id')->first();
        $this->assertStringContainsString('через API', (string) $history->comment);
        $this->assertStringContainsString('Агент клиента', (string) $history->comment);

        $foreign = Order::factory()->create(['user_id' => User::factory()->create()->id]);
        $this->api('POST', "/orders/{$foreign->id}/cancel")->assertStatus(404);
    }

    #[Test]
    #[TestDox('Резервы: подтверждение и уменьшение состава с кодами legacy (increase_forbidden, stale_items_version)')]
    public function reserve_confirm_and_items(): void
    {
        config(['order_reserve.enabled' => true, 'order_reserve.canary' => '']);
        $this->client->update(['reserve_allowed' => true]);

        $order = Order::factory()->create([
            'user_id' => $this->client->id, 'status' => OrderStatus::READY_FOR_SHIPMENT,
            'reserve' => true, 'reserved_until' => now()->addHours(20), 'total_amount' => 3000,
        ]);
        $product = Product::factory()->create();
        $item = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'name' => $product->name,
            'line_number' => 1, 'quantity' => 3, 'price' => 1000, 'final_price' => 1000, 'subtotal' => 3000,
        ]);

        $this->api('POST', "/reserves/{$order->id}/items", ['base_items_version' => 0, 'items' => [['item_id' => $item->id, 'quantity' => 5]]])
            ->assertStatus(422)->assertJsonPath('errors.0.code', 'increase_forbidden');

        $this->api('POST', "/reserves/{$order->id}/items", ['base_items_version' => 9, 'items' => [['item_id' => $item->id, 'quantity' => 1]]])
            ->assertStatus(409)->assertJsonPath('errors.0.code', 'stale_items_version');

        $this->api('POST', "/reserves/{$order->id}/items", ['base_items_version' => 0, 'items' => [['item_id' => $item->id, 'quantity' => 1]]])
            ->assertOk()->assertJsonPath('data.total_amount', 1000);
        $this->assertSame(1, (int) $item->refresh()->quantity);

        $this->api('POST', "/reserves/{$order->uuid}/confirm")->assertOk()->assertJsonPath('data.confirmed', true);
        $this->assertFalse($order->refresh()->reserve);
        Queue::assertPushed(PublishOrderToErpJob::class, fn (PublishOrderToErpJob $job) => $job->payload['event'] === 'order.confirmed');
    }
}
