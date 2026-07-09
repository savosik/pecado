<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Feed\YandexMarketFeedBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class YandexMarketFeedTest extends TestCase
{
    use RefreshDatabase;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        // Чистим кэш-файл между тестами: ensure() отдаёт готовый файл, поэтому
        // остаток от прошлого прогона мог бы подменить свежие данные.
        @unlink(app(YandexMarketFeedBuilder::class)->path());

        // Целевой склад фида (config feed.yandex_market.warehouse).
        $this->warehouse = Warehouse::factory()->create([
            'name' => config('feed.yandex_market.warehouse'),
        ]);
    }

    /**
     * Товар активной категории с заданным остатком на целевом складе.
     */
    protected function productInStock(float $price, int $qty): Product
    {
        $category = Category::factory()->create(['is_active' => true]);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'base_price' => $price,
        ]);
        $product->warehouses()->attach($this->warehouse->id, ['quantity' => $qty]);

        return $product;
    }

    public function test_route_returns_valid_yml_with_retail_price_and_warehouse_stock(): void
    {
        $product = $this->productInStock(1990.00, 7);

        $response = $this->get('/feed/yandex-market.yml');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=utf-8');

        // BinaryFileResponse не буферизует тело — читаем сгенерированный файл.
        $body = file_get_contents(app(YandexMarketFeedBuilder::class)->path());
        $this->assertStringContainsString('<yml_catalog', $body);
        $this->assertStringContainsString($product->name, $body);
        // Розничная цена base_price, без oldprice.
        $this->assertStringContainsString('<price>1990</price>', $body);
        $this->assertStringNotContainsString('<oldprice>', $body);
        // Остаток и доступность — с целевого склада.
        $this->assertStringContainsString('<count>7</count>', $body);
        $this->assertMatchesRegularExpression('/<offer[^>]*available="true"/', $body);
    }

    public function test_excludes_products_without_stock_on_target_warehouse(): void
    {
        $inStock = $this->productInStock(500, 3);
        $outOfStock = $this->productInStock(500, 0);

        // Товар с остатком на другом складе — не считается.
        $otherWarehouse = Warehouse::factory()->create(['name' => 'Тюмень Основной']);
        $elsewhere = $this->productInStock(500, 0);
        $elsewhere->warehouses()->attach($otherWarehouse->id, ['quantity' => 99]);

        $xml = file_get_contents(app(YandexMarketFeedBuilder::class)->build());

        $this->assertStringContainsString($inStock->name, $xml);
        $this->assertStringNotContainsString($outOfStock->name, $xml);
        $this->assertStringNotContainsString($elsewhere->name, $xml);
    }

    public function test_excludes_products_of_inactive_categories(): void
    {
        $inactive = Category::factory()->create(['is_active' => false]);
        $hidden = Product::factory()->create(['category_id' => $inactive->id, 'base_price' => 500]);
        $hidden->warehouses()->attach($this->warehouse->id, ['quantity' => 5]);

        $shown = $this->productInStock(500, 5);

        $xml = file_get_contents(app(YandexMarketFeedBuilder::class)->build());

        $this->assertStringContainsString($shown->name, $xml);
        $this->assertStringNotContainsString($hidden->name, $xml);
    }

    public function test_excludes_zero_price_products(): void
    {
        $free = $this->productInStock(0, 5);
        $paid = $this->productInStock(100, 5);

        $xml = file_get_contents(app(YandexMarketFeedBuilder::class)->build());

        $this->assertStringContainsString($paid->name, $xml);
        $this->assertStringNotContainsString($free->name, $xml);
    }

    public function test_feed_file_survives_exports_cleanup(): void
    {
        $this->productInStock(100, 5);
        $path = app(YandexMarketFeedBuilder::class)->build();
        $this->assertFileExists($path);

        // Файл фида лежит в exports/, но с префиксом feed_ — cleanup его щадит.
        $this->artisan('exports:cleanup')->assertSuccessful();

        $this->assertFileExists($path);
    }
}
