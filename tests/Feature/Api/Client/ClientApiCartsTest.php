<?php

namespace Tests\Feature\Api\Client;

use App\Contracts\Pricing\PriceResult;
use App\Contracts\Pricing\PriceServiceInterface;
use App\Contracts\Stock\StockServiceInterface;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class ClientApiCartsTest extends ClientApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $prices = $this->createMock(PriceServiceInterface::class);
        $prices->method('getBasePriceForUser')->willReturn(100.0);
        $prices->method('getUserPrice')->willReturn(90.0);
        $prices->method('getPriceResult')->willReturn(PriceResult::withIndividualPrice(100.0, 90.0));
        $prices->method('getPriceMapForProducts')->willReturnCallback(function (iterable $products) {
            $map = [];
            foreach ($products as $p) {
                $map[$p->id] = PriceResult::withIndividualPrice(100.0, 90.0);
            }

            return $map;
        });
        $this->app->instance(PriceServiceInterface::class, $prices);

        $stocks = $this->createMock(StockServiceInterface::class);
        $stocks->method('getStock')->willReturn(['available' => 10, 'preorder' => 5]);
        $stocks->method('getAvailableStock')->willReturn(10);
        $stocks->method('getPreorderStock')->willReturn(5);
        $this->app->instance(StockServiceInterface::class, $stocks);
    }

    private function product(string $code): Product
    {
        return Product::factory()->create(['code' => $code, 'sku' => 'SKU-'.$code, 'name' => 'Товар '.$code]);
    }

    #[Test]
    #[TestDox('Сценарий: создать корзину → импорт строк → задать количества → штрихкод → состав и итоги')]
    public function full_cart_scenario(): void
    {
        $a = $this->product('A');
        $b = $this->product('B');
        ProductBarcode::create(['product_id' => $b->id, 'barcode' => '4601111111111']);

        $created = $this->api('POST', '/carts', ['name' => 'Закупка сентябрь'], ['Idempotency-Key' => 'cart-1'])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Закупка сентябрь')
            ->assertJsonPath('data.is_active', true);
        $cartId = $created->json('data.id');

        // Повтор с тем же ключом не создаёт вторую корзину
        $this->api('POST', '/carts', ['name' => 'Закупка сентябрь'], ['Idempotency-Key' => 'cart-1'])
            ->assertJsonPath('data.id', $cartId)
            ->assertJsonPath('meta.idempotent_replay', true);
        $this->assertSame(1, $this->client->carts()->count());

        $this->api('POST', "/carts/{$cartId}/import", [
            'rows' => [
                ['identifier' => 'SKU-A', 'quantity' => 3],
                ['identifier' => 'нет', 'quantity' => 1],
                ['identifier' => 'B', 'quantity' => 'x'],
            ],
        ])->assertOk()
            ->assertJsonPath('meta.added_count', 1)
            ->assertJsonCount(2, 'data.unresolved');

        // Урезание по остатку: 10 в наличии + 5 предзаказ = максимум 15
        $this->api('PUT', "/carts/{$cartId}/items", ['identifier' => 'A', 'quantity' => 20])->assertOk()
            ->assertJsonPath('data.instock', 10)
            ->assertJsonPath('data.preorder', 5)
            ->assertJsonPath('data.max_total', 15);

        $this->api('POST', "/carts/{$cartId}/barcode", ['barcode' => '4601111111111', 'quantity' => 2])->assertOk()
            ->assertJsonPath('data.status', 'success')
            ->assertJsonPath('data.product_id', $b->id);

        $this->api('POST', "/carts/{$cartId}/barcode", ['barcode' => '0000000000000'])->assertStatus(404);

        $details = $this->api('GET', '/carts/active')->assertOk();
        $this->assertSame($cartId, $details->json('data.id'));
        $this->assertSame(17, $details->json('data.total_quantity'));

        $this->api('GET', '/carts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.items_count', 17);

        $this->api('GET', "/carts/{$cartId}/promotions")->assertOk()->assertJsonStructure(['data' => ['progress', 'promo_items']]);

        $this->api('POST', "/carts/{$cartId}/clear")->assertOk()->assertJsonPath('data.cleared', true);
        $this->assertSame(0, Cart::find($cartId)->items()->count());
    }

    #[Test]
    #[TestDox('Количества строки: quantity — лежит, requested — просили, trimmed — урезано; clamped наружу не выходит')]
    public function line_quantities_are_explicit(): void
    {
        $a = $this->product('A');
        $b = $this->product('B');
        ProductBarcode::create(['product_id' => $b->id, 'barcode' => '4602222222222']);

        // Просили 1 — легла 1, урезать нечего
        $this->api('POST', '/carts/active/items', ['identifier' => 'A', 'quantity' => 1])->assertOk()
            ->assertJsonPath('data.quantity', 1)
            ->assertJsonPath('data.requested', 1)
            ->assertJsonPath('data.trimmed', 0)
            ->assertJsonMissingPath('data.clamped');

        // Добавка к лежащему: 1 + 16 = 17 при максимуме 15 → урезано 2
        $this->api('POST', '/carts/active/items', ['identifier' => 'A', 'quantity' => 16])->assertOk()
            ->assertJsonPath('data.quantity', 15)
            ->assertJsonPath('data.requested', 17)
            ->assertJsonPath('data.trimmed', 2)
            ->assertJsonPath('data.instock', 10)
            ->assertJsonPath('data.preorder', 5);

        $this->api('PUT', '/carts/active/items', ['identifier' => 'A', 'quantity' => 4])->assertOk()
            ->assertJsonPath('data.quantity', 4)
            ->assertJsonPath('data.requested', 4)
            ->assertJsonPath('data.trimmed', 0);

        $this->api('POST', '/carts/active/barcode', ['barcode' => '4602222222222', 'quantity' => 20])->assertOk()
            ->assertJsonPath('data.status', 'partial')
            ->assertJsonPath('data.quantity', 15)
            ->assertJsonPath('data.trimmed', 5)
            ->assertJsonMissingPath('data.clamped');

        $bulk = $this->api('PUT', '/carts/active/items/bulk', ['rows' => [['identifier' => 'A', 'quantity' => 30]]])->assertOk();
        $bulk->assertJsonPath('data.items.0.product_id', $a->id);
        $line = $bulk->json('data.items.0');
        $this->assertSame(['quantity' => 15, 'requested' => 30, 'trimmed' => 15], array_intersect_key($line, array_flip(['quantity', 'requested', 'trimmed'])));
        $this->assertArrayNotHasKey('clamped', $line);
    }

    #[Test]
    #[TestDox('Пакетные количества: применяются найденные, ненайденные в meta; режим replace очищает корзину')]
    public function bulk_quantities_and_replace_import(): void
    {
        $a = $this->product('A');
        $b = $this->product('B');

        $this->api('PUT', '/carts/active/items/bulk', ['rows' => [
            ['identifier' => 'A', 'quantity' => 2],
            ['identifier' => 'нет', 'quantity' => 2],
        ]])->assertOk()->assertJsonPath('meta.applied', 1)->assertJsonPath('meta.unresolved.0.reason', 'Товар не найден');

        $this->api('POST', '/carts/active/import', ['mode' => 'replace', 'rows' => [['identifier' => 'B', 'quantity' => 4]]])->assertOk();

        $cart = $this->client->carts()->active()->first();
        $this->assertSame([$b->id], $cart->items()->pluck('product_id')->unique()->values()->all());
        $this->assertSame(4, (int) $cart->items()->sum('quantity'));
    }

    #[Test]
    #[TestDox('Чужая корзина — 404, последняя корзина не удаляется, активная переключается')]
    public function ownership_and_lifecycle(): void
    {
        $foreign = Cart::factory()->create(['user_id' => User::factory()->create()->id]);
        $this->api('GET', "/carts/{$foreign->id}")->assertStatus(404)->assertJsonPath('errors.0.code', 'not_found');
        $this->api('GET', '/carts/abc')->assertStatus(422);

        $first = $this->api('GET', '/carts/active')->json('data.id');
        $this->api('DELETE', "/carts/{$first}")->assertStatus(422)->assertJsonPath('errors.0.code', 'business_rule');

        $second = $this->api('POST', '/carts', ['name' => 'Вторая'])->assertStatus(201)->json('data.id');
        $this->assertFalse(Cart::find($first)->is_active);

        $this->api('PATCH', "/carts/{$second}", ['name' => 'Переименована'])->assertOk()->assertJsonPath('data.name', 'Переименована');
        $this->api('POST', "/carts/{$first}/activate")->assertOk()->assertJsonPath('data.is_active', true);
        $this->api('DELETE', "/carts/{$second}")->assertOk()->assertJsonPath('data.deleted', true);
        $this->assertSame(1, $this->client->carts()->count());
    }
}
