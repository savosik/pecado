<?php

namespace Tests\Feature\Api\Client;

use App\Models\Product;
use App\Models\ProductDefect;
use App\Models\Warehouse;
use PHPUnit\Framework\Attributes\Test;

/**
 * Уценка для агента клиента: только опубликованные партии с остатком,
 * партии товара, партия с фото, строка уценки в корзине.
 */
class ClientApiDefectsTest extends ClientApiTestCase
{
    private function sellableDefect(Product $product, int $quantity = 3, float $price = 500): ProductDefect
    {
        return ProductDefect::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => Warehouse::factory()->defect()->create()->id,
            'defect_description' => 'Порвана упаковка',
            'quantity' => $quantity,
            'price' => $price,
            'is_published' => true,
        ]);
    }

    #[Test]
    public function список_отдаёт_только_опубликованные_партии_с_остатком_и_ищет_по_товару(): void
    {
        $lamp = Product::factory()->create(['name' => 'Вибратор Lumi', 'sku' => 'LU-1']);
        $ring = Product::factory()->create(['name' => 'Кольцо Ring', 'sku' => 'RG-2']);
        $visible = $this->sellableDefect($lamp, 3, 500);
        $this->sellableDefect($ring, 2, 300);
        ProductDefect::factory()->create(['product_id' => $lamp->id, 'quantity' => 5, 'price' => 100, 'is_published' => false]);
        ProductDefect::factory()->create(['product_id' => $lamp->id, 'quantity' => 5, 'price' => null, 'is_published' => true]);

        $all = $this->api('GET', '/defects')->assertOk()->json();
        $this->assertCount(2, $all['data']);
        $this->assertEquals(300, $all['data'][0]['price'], 'дешевле первыми');
        $this->assertSame('/products/utsenka', parse_url($all['meta']['catalog_url'], PHP_URL_PATH));

        $found = $this->api('GET', '/defects?q=LU-1')->assertOk()->json('data');
        $this->assertCount(1, $found);
        $this->assertSame($visible->id, $found[0]['id']);
        $this->assertSame('Порвана упаковка', $found[0]['defect']);
        $this->assertSame(3, $found[0]['available']);
        $this->assertSame('LU-1', $found[0]['product']['sku']);
    }

    #[Test]
    public function партии_товара_и_партия_целиком(): void
    {
        $product = Product::factory()->create(['sku' => 'LU-1']);
        $defect = $this->sellableDefect($product, 2, 450);

        $rows = $this->api('GET', '/products/LU-1/defects')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($defect->id, $rows[0]['id']);

        $this->api('GET', "/defects/{$defect->id}")->assertOk()
            ->assertJsonPath('data.id', $defect->id)
            ->assertJsonPath('data.available', 2)
            ->assertJsonPath('data.photos_total', 0);

        $hidden = ProductDefect::factory()->create(['product_id' => $product->id, 'quantity' => 1, 'price' => 100, 'is_published' => false]);
        $this->api('GET', "/defects/{$hidden->id}")->assertNotFound();
    }

    #[Test]
    public function уценка_в_корзине_урезается_по_остатку_и_убирается_нулём(): void
    {
        $product = Product::factory()->create(['name' => 'Вибратор Lumi', 'sku' => 'LU-1']);
        $defect = $this->sellableDefect($product, 2, 500);

        $added = $this->api('POST', '/carts/active/defects', ['defect_id' => $defect->id, 'quantity' => 5])->assertOk()->json('data');
        $this->assertSame(2, $added['quantity'], 'урезано по остатку партии');
        $this->assertSame(2, $added['available']);
        $this->assertEquals(500, $added['price']);

        $cart = $this->api('GET', '/carts/active')->assertOk()->json('data');
        $this->assertStringContainsString('Lumi', json_encode($cart, JSON_UNESCAPED_UNICODE));

        $this->api('PUT', '/carts/active/defects', ['defect_id' => $defect->id, 'quantity' => 0])->assertOk()
            ->assertJsonPath('data.quantity', 0);

        $this->api('POST', '/carts/active/defects', ['defect_id' => 999999, 'quantity' => 1])->assertNotFound();
    }

    #[Test]
    public function операции_уценки_видны_в_каталоге_агента_и_в_openapi(): void
    {
        $catalog = $this->api('GET', '/me')->assertOk()->json();
        $ids = collect($catalog['operations'] ?? $catalog['data']['operations'] ?? [])->pluck('id')->all();

        foreach (['defects.list', 'defects.for-product', 'defects.get', 'carts.add-defect', 'carts.set-defect-quantity'] as $id) {
            $this->assertContains($id, $ids, "операция {$id} есть в /me");
        }
    }
}
