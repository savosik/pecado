<?php

namespace Tests\Feature\Api\Client;

use App\Contracts\Currency\UserCurrencyResolverInterface;
use App\Contracts\Pricing\PriceResult;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Enums\DebtLevel;
use App\Exceptions\DebtRestrictionException;
use App\Jobs\PublishOrderToErpJob;
use App\Models\Order;
use App\Models\Product;
use App\Services\Debt\DebtGate;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class ClientApiCheckoutTest extends ClientApiTestCase
{
    /** @var array<string, array{available: int, preorder: int}> */
    private array $stock = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([PublishOrderToErpJob::class]);

        $prices = $this->createMock(PriceServiceInterface::class);
        $prices->method('getBasePriceForUser')->willReturn(120.0);
        $prices->method('getUserPrice')->willReturn(100.0);
        $prices->method('getBasePrice')->willReturn(120.0);
        $prices->method('getPriceResult')->willReturn(new PriceResult(120.0, 100.0, 16.67, true));
        $prices->method('convertPrice')->willReturnArgument(0);
        $this->app->instance(PriceServiceInterface::class, $prices);

        $stocks = $this->createMock(StockServiceInterface::class);
        $stocks->method('getStock')->willReturnCallback(fn (Product $p) => $this->stock[$p->code] ?? ['available' => 10, 'preorder' => 5]);
        $this->app->instance(StockServiceInterface::class, $stocks);

        $currency = $this->createMock(UserCurrencyResolverInterface::class);
        $currency->method('resolve')->willReturn(null);
        $this->app->instance(UserCurrencyResolverInterface::class, $currency);
    }

    private function product(string $code, int $available = 10, int $preorder = 5): Product
    {
        $this->stock[$code] = ['available' => $available, 'preorder' => $preorder];

        return Product::factory()->create(['code' => $code, 'sku' => 'SKU-'.$code, 'name' => 'Товар '.$code]);
    }

    private function fillCart(): void
    {
        $this->api('PUT', '/carts/active/items/bulk', ['rows' => [
            ['identifier' => 'A', 'quantity' => 3],
            ['identifier' => 'B', 'quantity' => 12], // 10 в наличии + 2 предзаказ
        ]])->assertOk();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function snapshot(): array
    {
        return Order::query()->with('items')->orderBy('type')->get()->map(fn (Order $o) => [
            'type' => $o->type?->value,
            'company_id' => $o->company_id,
            'delivery_address' => $o->delivery_address,
            'comment' => $o->comment,
            'delivery_method' => $o->delivery_method?->value,
            'items' => $o->items->map(fn ($i) => [(int) $i->product_id, (int) $i->quantity, round((float) $i->price, 2)])->sortBy(0)->values()->all(),
        ])->all();
    }

    #[Test]
    #[TestDox('Паритет: одна корзина через кабинет и через API даёт одинаковые заказы')]
    public function cabinet_and_api_checkout_produce_the_same_orders(): void
    {
        $this->product('A');
        $this->product('B');

        $this->fillCart();
        $this->actingAs($this->client)->post('/checkout', [
            'company_id' => $this->company->id,
            'delivery_method' => 'delivery',
            'delivery_address' => 'Москва, Тверская 1',
            'comment' => 'Позвонить перед доставкой',
        ])->assertRedirect();
        $cabinet = $this->snapshot();
        $this->assertCount(2, $cabinet, 'наличие и предзаказ — два заказа');
        Order::query()->forceDelete();

        $this->fillCart();
        $this->api('POST', '/checkout', [
            'delivery_method' => 'delivery',
            'delivery_address' => 'Москва, Тверская 1',
            'comment' => 'Позвонить перед доставкой',
        ], ['Idempotency-Key' => 'chk-1'])->assertStatus(201)->assertJsonPath('meta.total_orders', 2);

        $this->assertEquals($cabinet, $this->snapshot());
        $this->assertSame(0, $this->client->carts()->active()->first()->items()->count(), 'корзина очищена');
    }

    #[Test]
    #[TestDox('Превью показывает группы и конфликты остатков, ничего не пишет; normalize чинит корзину')]
    public function preview_and_normalize(): void
    {
        $this->product('A');
        $this->product('B');
        $this->fillCart();

        $this->stock['B'] = ['available' => 4, 'preorder' => 0];

        $preview = $this->api('GET', '/checkout')->assertOk();
        $preview->assertJsonPath('data.company.id', $this->company->id)
            ->assertJsonPath('data.debt_restriction', null)
            ->assertJsonCount(1, 'data.stock_conflicts')
            ->assertJsonPath('data.stock_conflicts.0.available', 4);
        $this->assertSame(0, Order::count());

        $this->api('POST', '/checkout', ['delivery_method' => 'pickup'], ['Idempotency-Key' => 'chk-2'])
            ->assertStatus(409)->assertJsonPath('errors.0.code', 'stock_changed');

        $this->api('POST', '/checkout/normalize')->assertOk()->assertJsonPath('data.adjusted', 1);
        $this->api('GET', '/checkout')->assertOk()->assertJsonCount(0, 'data.stock_conflicts');

        $this->api('POST', '/checkout', ['delivery_method' => 'pickup'], ['Idempotency-Key' => 'chk-3'])->assertStatus(201);
    }

    #[Test]
    #[TestDox('Ключ обязателен, повтор не создаёт заказы дважды; доставка без адреса — 422; резерв вне режима — 403')]
    public function submit_guards(): void
    {
        $this->product('A');
        $this->api('PUT', '/carts/active/items', ['identifier' => 'A', 'quantity' => 1])->assertOk();

        $this->api('POST', '/checkout', ['delivery_method' => 'pickup'])->assertStatus(422)->assertJsonPath('errors.0.code', 'idempotency_key_required');
        $this->api('POST', '/checkout', ['delivery_method' => 'delivery'], ['Idempotency-Key' => 'g-1'])->assertStatus(422)->assertJsonPath('errors.0.field', 'delivery_address');

        config(['order_reserve.enabled' => false]);
        $this->api('POST', '/checkout', ['delivery_method' => 'pickup', 'reserve' => true], ['Idempotency-Key' => 'g-2'])
            ->assertStatus(403)->assertJsonPath('errors.0.code', 'reserve_unavailable');

        $first = $this->api('POST', '/checkout', ['delivery_method' => 'pickup'], ['Idempotency-Key' => 'g-3'])->assertStatus(201);
        $again = $this->api('POST', '/checkout', ['delivery_method' => 'pickup'], ['Idempotency-Key' => 'g-3'])->assertStatus(201);
        $this->assertSame($first->json('data.0.id'), $again->json('data.0.id'));
        $this->assertSame(1, Order::count());
        Queue::assertPushed(PublishOrderToErpJob::class, 1);

        $this->api('POST', '/checkout', ['delivery_method' => 'pickup'], ['Idempotency-Key' => 'g-4'])
            ->assertStatus(422)->assertJsonPath('errors.0.code', 'nothing_to_checkout');
    }

    #[Test]
    #[TestDox('Лестница долга: 422 debt_restricted с payload, заказ не создаётся')]
    public function debt_restriction_is_reported(): void
    {
        $this->product('A');
        $this->api('PUT', '/carts/active/items', ['identifier' => 'A', 'quantity' => 1])->assertOk();

        $gate = $this->createMock(DebtGate::class);
        $gate->method('check')->willThrowException(new DebtRestrictionException(DebtLevel::NO_ORDERS, 15000.0, 'ООО Ромашка', true, 'Заказы приостановлены: просрочка 15 000 ₽.'));
        $this->app->instance(DebtGate::class, $gate);

        $this->api('GET', '/checkout')->assertOk()->assertJsonPath('data.debt_restriction.blocks_all_orders', true);

        $this->api('POST', '/checkout', ['delivery_method' => 'pickup'], ['Idempotency-Key' => 'd-1'])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'debt_restricted')
            ->assertJsonStructure(['meta' => ['debt']]);
        $this->assertSame(0, Order::count());
    }

    #[Test]
    #[TestDox('Повтор заказа кладёт позиции в корзину в режимах merge и replace')]
    public function repeat_order(): void
    {
        $a = $this->product('A');
        $b = $this->product('B');
        $order = Order::factory()->create(['user_id' => $this->client->id, 'company_id' => $this->company->id]);
        \App\Models\OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $a->id, 'quantity' => 2]);

        $this->api('PUT', '/carts/active/items', ['identifier' => 'B', 'quantity' => 1])->assertOk();

        $this->api('POST', "/orders/{$order->id}/repeat")->assertOk()->assertJsonPath('data.added_count', 1)->assertJsonPath('data.mode', 'merge');
        $cart = $this->client->carts()->active()->first();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $cart->items()->pluck('product_id')->unique()->values()->all());

        $this->api('POST', "/orders/{$order->id}/repeat", ['mode' => 'replace'])->assertOk();
        $this->assertSame([$a->id], $cart->items()->pluck('product_id')->unique()->values()->all());
        $this->assertSame(2, (int) $cart->items()->sum('quantity'), 'replace очищает корзину и кладёт ровно позиции заказа');
    }
}
