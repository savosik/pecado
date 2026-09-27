<?php

namespace Tests\Feature\Api\Client;

use App\Contracts\Currency\UserCurrencyResolverInterface;
use App\Contracts\Pricing\PriceResult;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductBarcode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class ClientApiCatalogTest extends ClientApiTestCase
{
    /** @var array<int, array{price: float, individual: ?float, available: int, preorder: int}> */
    private array $facts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $prices = $this->createMock(PriceServiceInterface::class);
        $prices->method('getPriceResult')->willReturnCallback(fn (Product $p) => $this->priceOf($p));
        $prices->method('getPriceMapForProducts')->willReturnCallback(function (iterable $products) {
            $map = [];
            foreach ($products as $p) {
                $map[$p->id] = $this->priceOf($p);
            }

            return $map;
        });
        $this->app->instance(PriceServiceInterface::class, $prices);

        $stocks = $this->createMock(StockServiceInterface::class);
        $stocks->method('getStock')->willReturnCallback(fn (Product $p) => [
            'available' => $this->facts[$p->id]['available'] ?? 0,
            'preorder' => $this->facts[$p->id]['preorder'] ?? 0,
        ]);
        $stocks->method('getStockMapsByIds')->willReturnCallback(function (array $ids) {
            $maps = ['available' => [], 'preorder' => []];
            foreach ($ids as $id) {
                $maps['available'][$id] = $this->facts[$id]['available'] ?? 0;
                $maps['preorder'][$id] = $this->facts[$id]['preorder'] ?? 0;
            }

            return $maps;
        });
        $this->app->instance(StockServiceInterface::class, $stocks);

        $currency = $this->createMock(UserCurrencyResolverInterface::class);
        $currency->method('resolve')->willReturn(null);
        $this->app->instance(UserCurrencyResolverInterface::class, $currency);
    }

    private function priceOf(Product $p): PriceResult
    {
        $fact = $this->facts[$p->id] ?? ['price' => (float) $p->base_price, 'individual' => null];

        return $fact['individual'] !== null
            ? PriceResult::withIndividualPrice($fact['price'], $fact['individual'])
            : PriceResult::withoutDiscount($fact['price']);
    }

    private function product(string $code, float $price, ?float $individual = null, int $available = 0, int $preorder = 0, array $extra = []): Product
    {
        $product = Product::factory()->create(array_merge([
            'code' => $code,
            'sku' => 'SKU-'.$code,
            'barcode' => '460'.str_pad((string) crc32($code), 10, '0', STR_PAD_LEFT),
            'name' => 'Товар '.$code,
            'base_price' => $price,
        ], $extra));

        $this->facts[$product->id] = compact('price', 'individual', 'available', 'preorder');

        return $product;
    }

    #[Test]
    #[TestDox('Цены по списку идентификаторов: найденные в порядке запроса, ненайденные и неоднозначные в meta')]
    public function prices_by_identifiers(): void
    {
        $a = $this->product('A-1', 120.0, 100.0, available: 5);
        $b = $this->product('B-2', 50.0);
        ProductBarcode::create(['product_id' => $b->id, 'barcode' => '4600000000001']);
        $c = $this->product('C-3', 10.0);
        ProductBarcode::create(['product_id' => $c->id, 'barcode' => '4600000000001']); // тот же штрихкод у двух

        $response = $this->api('GET', '/catalog/prices?'.http_build_query([
            'identifiers' => ['SKU-B-2', $a->external_id, 'нет-такого', '4600000000001'],
        ]))->assertOk();

        $response->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', 'B-2')
            ->assertJsonPath('data.0.price', 50)
            ->assertJsonPath('data.0.slug', $b->slug, 'slug нужен агенту для ссылки на карточку')
            ->assertJsonPath('data.1.code', 'A-1')
            ->assertJsonPath('data.1.base_price', 120)
            ->assertJsonPath('data.1.price', 100)
            ->assertJsonPath('data.1.discount_percent', 16.67)
            ->assertJsonPath('meta.currency_code', 'RUB')
            ->assertJsonPath('meta.missing', ['нет-такого'])
            ->assertJsonPath('meta.ambiguous', ['4600000000001']);

        $this->assertArrayNotHasKey('cost_price', $response->json('data.0'));
    }

    #[Test]
    #[TestDox('Без identifiers — весь каталог по курсору, потолок 500, вторая страница без повторов')]
    public function prices_feed_is_cursor_paginated(): void
    {
        foreach (range(1, 5) as $i) {
            $this->product('P-'.$i, 10.0 * $i);
        }

        $first = $this->api('GET', '/catalog/prices?per_page=2')->assertOk();
        $first->assertJsonCount(2, 'data')->assertJsonPath('meta.has_more', true)->assertJsonPath('meta.per_page', 2);

        $second = $this->api('GET', '/catalog/prices?per_page=2&cursor='.$first->json('meta.next_cursor'))->assertOk();
        $this->assertNotEquals($first->json('data.0.code'), $second->json('data.0.code'));

        $this->api('GET', '/catalog/prices?per_page=500')->assertOk()->assertJsonPath('meta.per_page', 500);
        $this->api('GET', '/catalog/prices?per_page=9999')->assertStatus(422)->assertJsonPath('errors.0.field', 'per_page');
        $this->api('GET', '/catalog/prices?per_page=0')->assertStatus(422);
    }

    #[Test]
    #[TestDox('Валюта задаётся кодом и конвертирует цены')]
    public function prices_convert_to_requested_currency(): void
    {
        Currency::factory()->create(['code' => 'RUB', 'is_base' => true, 'exchange_rate' => 1, 'rate_coefficient' => 1]);
        $byn = Currency::factory()->create(['code' => 'BYN', 'symbol' => 'Br', 'is_base' => false, 'exchange_rate' => 30, 'rate_coefficient' => 1]);
        $this->product('V-1', 300.0);

        $response = $this->api('GET', '/catalog/prices?identifiers[]=V-1&currency=byn')->assertOk();

        $this->assertSame('BYN', $response->json('meta.currency_code'));
        $this->assertSame('BYN', $response->json('data.0.currency_code'));
        $this->assertNotEquals(300, $response->json('data.0.price'));
        $this->assertSame($byn->symbol, $response->json('meta.currency_symbol'));
    }

    #[Test]
    #[TestDox('Остатки по списку и по курсору, предзаказ несёт срок поставки')]
    public function stocks_by_identifiers_and_feed(): void
    {
        $in = $this->product('S-1', 10.0, available: 7);
        $pre = $this->product('S-2', 10.0, available: 0, preorder: 3);

        $this->api('GET', '/catalog/stocks?identifiers[]=S-1&identifiers[]=S-2')->assertOk()
            ->assertJsonPath('data.0.available', 7)
            ->assertJsonPath('data.0.preorder_lead_days', null)
            ->assertJsonPath('data.1.preorder', 3)
            ->assertJsonPath('data.1.preorder_lead_days.min', 7);

        $this->api('GET', '/catalog/stocks?per_page=1')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.has_more', true);
    }

    #[Test]
    #[TestDox('Карточка товара по uuid, коду, артикулу, штрихкоду и дополнительному штрихкоду; неизвестный — 404')]
    public function product_card_by_any_identifier(): void
    {
        $product = $this->product('K-1', 99.0, available: 2, extra: ['slug' => 'tovar-k-1']);
        ProductBarcode::create(['product_id' => $product->id, 'barcode' => '4601234567890']);

        foreach ([$product->external_id, 'K-1', 'SKU-K-1', $product->barcode, '4601234567890'] as $identifier) {
            $this->api('GET', '/catalog/products/'.$identifier)->assertOk()
                ->assertJsonPath('data.code', 'K-1')
                ->assertJsonPath('data.available', 2)
                ->assertJsonPath('data.url', route('products.show', 'tovar-k-1'));
        }

        $this->api('GET', '/catalog/products/NO-SUCH-1')->assertStatus(404)->assertJsonPath('errors.0.code', 'not_found');

        $this->api('GET', '/catalog/barcode/4601234567890')->assertOk()->assertJsonPath('data.code', 'K-1');
        $this->api('GET', '/catalog/barcode/K-1')->assertStatus(404);
    }

    #[Test]
    #[TestDox('Поиск: точное совпадение по артикулу отдаёт ровно этот товар с ценой и остатком')]
    public function search_exact_match(): void
    {
        $this->product('F-1', 10.0, available: 1);
        $target = $this->product('F-2', 20.0, available: 4);

        $this->api('GET', '/catalog/search?q=SKU-F-2')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'F-2')
            ->assertJsonPath('data.0.available', 4)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.no_exact_match', false);

        $this->api('GET', '/catalog/search?q=x')->assertStatus(422);
    }

    #[Test]
    #[TestDox('Паритет с legacy: цены и остатки v1 совпадают с /prices и /stocks на одном наборе')]
    public function parity_with_legacy_feeds(): void
    {
        $this->product('L-1', 120.0, 100.0, available: 3, preorder: 1);
        $this->product('L-2', 80.0, null, available: 0, preorder: 5);

        $legacyPrices = collect($this->getJson("/api/client-api/{$this->token->token}/prices")->assertOk()->json('data'))->keyBy('code');
        $legacyStocks = collect($this->getJson("/api/client-api/{$this->token->token}/stocks")->assertOk()->json('data'))->keyBy('code');

        $v1Prices = collect($this->api('GET', '/catalog/prices')->assertOk()->json('data'))->keyBy('code');
        $v1Stocks = collect($this->api('GET', '/catalog/stocks')->assertOk()->json('data'))->keyBy('code');

        foreach (['L-1', 'L-2'] as $code) {
            $this->assertEquals($legacyPrices[$code]['base_price'], $v1Prices[$code]['base_price'], $code);
            $this->assertEquals($legacyPrices[$code]['price'], $v1Prices[$code]['price'], $code);
            $this->assertSame($legacyStocks[$code]['available'], $v1Stocks[$code]['available'], $code);
            $this->assertSame($legacyStocks[$code]['preorder'], $v1Stocks[$code]['preorder'], $code);
        }
    }
}
