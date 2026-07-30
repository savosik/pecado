<?php

namespace Tests\Feature\Api;

use App\Contracts\Currency\UserCurrencyResolverInterface;
use App\Contracts\Pricing\PriceResult;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Events\OrderCreated;
use App\Events\OrderUpdated;
use App\Jobs\PublishOrderToErpJob;
use App\Models\ApiToken;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Порядок событий у заказов клиентского API — зеркало CheckoutServicePublishTest.
 *
 * Каналы обязаны вести себя одинаково: заказ создаётся, сумма фиксируется без
 * `OrderUpdated`, а `OrderCreated` уходит один раз после коммита. Иначе в 1С
 * прилетает `order.updated` раньше `order.created` — по документу, которого
 * с точки зрения шины ещё не существует.
 */
class ClientApiOrderPublishTest extends TestCase
{
    use RefreshDatabase;

    private ApiToken $token;

    /** @var array<string, array{available: int, preorder: int}> */
    private array $stockMap = [];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['erp_id' => 'u-erp-api']);

        $this->token = ApiToken::create([
            'user_id' => $user->id,
            'name' => 'test',
            'is_active' => true,
        ]);

        Company::factory()->create(['user_id' => $user->id, 'tax_id' => '7707083893']);

        $priceMock = $this->createMock(PriceServiceInterface::class);
        $priceMock->method('getPriceResult')->willReturn(new PriceResult(120.0, 100.0, 16.67, true));
        $this->app->instance(PriceServiceInterface::class, $priceMock);

        $stockMock = $this->createMock(StockServiceInterface::class);
        $stockMock->method('getStock')->willReturnCallback(
            fn (Product $product) => $this->stockMap[$product->code] ?? ['available' => 0, 'preorder' => 0],
        );
        $this->app->instance(StockServiceInterface::class, $stockMock);

        $currencyMock = $this->createMock(UserCurrencyResolverInterface::class);
        $currencyMock->method('resolve')->willReturn(null);
        $this->app->instance(UserCurrencyResolverInterface::class, $currencyMock);
    }

    private function product(string $code, int $available, int $preorder = 0): Product
    {
        $this->stockMap[$code] = ['available' => $available, 'preorder' => $preorder];

        return Product::factory()->create(['code' => $code, 'external_id' => 'p-'.$code]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     */
    private function order(array $products): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/client-api/{$this->token->token}/orders", [
            'inn' => '7707083893',
            'products' => $products,
        ]);
    }

    #[Test]
    public function api_заказ_не_публикует_лишний_order_updated(): void
    {
        Event::fake([OrderCreated::class, OrderUpdated::class]);

        $this->product('ART-A', available: 10);

        $this->order([['identifier' => 'ART-A', 'quantity' => 5]])->assertStatus(201);

        Event::assertDispatched(OrderCreated::class, 1);
        Event::assertNotDispatched(OrderUpdated::class);
    }

    #[Test]
    public function api_заказ_публикует_ровно_одно_order_created_в_шину(): void
    {
        Queue::fake();

        $this->product('ART-A', available: 10);

        $this->order([['identifier' => 'ART-A', 'quantity' => 5]])->assertStatus(201);

        Queue::assertPushed(PublishOrderToErpJob::class, 1);
        Queue::assertPushed(
            PublishOrderToErpJob::class,
            fn (PublishOrderToErpJob $job) => ($job->payload['event'] ?? null) === 'order.created',
        );
    }

    #[Test]
    public function расщеплённый_заказ_публикует_по_одному_сообщению_на_каждый(): void
    {
        Queue::fake();

        $this->product('ART-A', available: 10);
        $this->product('ART-B', available: 0, preorder: 10);

        $this->order([
            ['identifier' => 'ART-A', 'quantity' => 5],
            ['identifier' => 'ART-B', 'quantity' => 4],
        ])->assertStatus(201);

        Queue::assertPushed(PublishOrderToErpJob::class, 2);
        Queue::assertNotPushed(
            PublishOrderToErpJob::class,
            fn (PublishOrderToErpJob $job) => ($job->payload['event'] ?? null) === 'order.updated',
        );
    }
}
