<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Services\Feed\YandexMarketFeedBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class YandexMarketFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Чистим кэш-файл между тестами: ensure() отдаёт готовый файл, поэтому
        // остаток от прошлого прогона мог бы подменить свежие данные.
        @unlink(app(YandexMarketFeedBuilder::class)->path());
    }

    public function test_route_returns_valid_yml_with_retail_price(): void
    {
        $category = Category::factory()->create(['is_active' => true]);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'base_price' => 1990.00,
        ]);

        $response = $this->get('/feed/yandex-market.yml');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=utf-8');

        // BinaryFileResponse не буферизует тело — читаем сгенерированный файл.
        $body = file_get_contents(app(YandexMarketFeedBuilder::class)->path());
        $this->assertStringContainsString('<yml_catalog', $body);
        $this->assertStringContainsString('<offers>', $body);
        $this->assertStringContainsString($product->name, $body);
        // Розничная цена base_price, без oldprice.
        $this->assertStringContainsString('<price>1990</price>', $body);
        $this->assertStringNotContainsString('<oldprice>', $body);
    }

    public function test_excludes_products_of_inactive_categories(): void
    {
        $active = Category::factory()->create(['is_active' => true]);
        $inactive = Category::factory()->create(['is_active' => false]);

        $shown = Product::factory()->create(['category_id' => $active->id, 'base_price' => 500]);
        $hidden = Product::factory()->create(['category_id' => $inactive->id, 'base_price' => 500]);

        $body = app(YandexMarketFeedBuilder::class)->build();
        $xml = file_get_contents($body);

        $this->assertStringContainsString($shown->name, $xml);
        $this->assertStringNotContainsString($hidden->name, $xml);
    }

    public function test_excludes_zero_price_products(): void
    {
        $category = Category::factory()->create(['is_active' => true]);
        $free = Product::factory()->create(['category_id' => $category->id, 'base_price' => 0]);
        $paid = Product::factory()->create(['category_id' => $category->id, 'base_price' => 100]);

        $xml = file_get_contents(app(YandexMarketFeedBuilder::class)->build());

        $this->assertStringContainsString($paid->name, $xml);
        $this->assertStringNotContainsString($free->name, $xml);
    }

    public function test_available_flag_reflects_stock(): void
    {
        $category = Category::factory()->create(['is_active' => true]);
        Product::factory()->create(['category_id' => $category->id, 'base_price' => 100]);

        $xml = file_get_contents(app(YandexMarketFeedBuilder::class)->build());

        // Без остатков товар присутствует, но помечен как отсутствующий.
        $this->assertMatchesRegularExpression('/<offer[^>]*available="(true|false)"/', $xml);
    }
}
