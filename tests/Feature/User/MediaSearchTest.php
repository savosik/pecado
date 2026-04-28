<?php

namespace Tests\Feature\User;

use App\Models\Brand;
use App\Models\Media;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function api(array $params = []): array
    {
        $response = $this->actingAs($this->user)->getJson('/cabinet/media/api?'.http_build_query($params));
        $response->assertOk();

        return $response->json();
    }

    private function fetchIds(array $params): array
    {
        return array_column($this->api($params)['data'] ?? [], 'id');
    }

    private function makeMedia(Product $product, array $overrides = []): Media
    {
        return Media::create(array_merge([
            'model_type' => Product::class,
            'model_id' => $product->id,
            'uuid' => (string) Str::uuid(),
            'collection_name' => 'main',
            'name' => 'photo',
            'file_name' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'conversions_disk' => 'public',
            'size' => 100_000,
            'manipulations' => [],
            'custom_properties' => [],
            'generated_conversions' => [],
            'responsive_images' => [],
            'order_column' => 1,
        ], $overrides));
    }

    private function enableFuzzy(): void
    {
        config([
            'search-cabinet.fuzzy_products' => true,
            'scout.driver' => 'collection',
        ]);
    }

    // ----------- Этап 1: фильтр по дате загрузки (C-8.2) -----------

    #[Test]
    public function filters_by_date_from(): void
    {
        $product = Product::factory()->create();
        $oldMedia = $this->makeMedia($product, ['file_name' => 'old.jpg']);
        $oldMedia->forceFill(['created_at' => Carbon::parse('2026-04-01 12:00:00')])->save();
        $newMedia = $this->makeMedia($product, ['file_name' => 'new.jpg']);
        $newMedia->forceFill(['created_at' => Carbon::parse('2026-04-29 12:00:00')])->save();

        $ids = $this->fetchIds(['date_from' => '2026-04-15']);
        $this->assertContains($newMedia->id, $ids);
        $this->assertNotContains($oldMedia->id, $ids);
    }

    #[Test]
    public function filters_by_date_to(): void
    {
        $product = Product::factory()->create();
        $oldMedia = $this->makeMedia($product, ['file_name' => 'old.jpg']);
        $oldMedia->forceFill(['created_at' => Carbon::parse('2026-04-01 12:00:00')])->save();
        $newMedia = $this->makeMedia($product, ['file_name' => 'new.jpg']);
        $newMedia->forceFill(['created_at' => Carbon::parse('2026-04-29 12:00:00')])->save();

        $ids = $this->fetchIds(['date_to' => '2026-04-15']);
        $this->assertContains($oldMedia->id, $ids);
        $this->assertNotContains($newMedia->id, $ids);
    }

    // ----------- Этап 1: фильтр по размеру (C-8.3) -----------

    #[Test]
    public function filters_by_size_from_mb(): void
    {
        $product = Product::factory()->create();
        $small = $this->makeMedia($product, ['file_name' => 'small.jpg', 'size' => 100 * 1024]); // 0.1 МБ
        $big = $this->makeMedia($product, ['file_name' => 'big.jpg', 'size' => 3 * 1024 * 1024]); // 3 МБ

        $ids = $this->fetchIds(['size_from_mb' => '1']);
        $this->assertContains($big->id, $ids);
        $this->assertNotContains($small->id, $ids);
    }

    #[Test]
    public function filters_by_size_to_mb(): void
    {
        $product = Product::factory()->create();
        $small = $this->makeMedia($product, ['file_name' => 'small.jpg', 'size' => 100 * 1024]);
        $big = $this->makeMedia($product, ['file_name' => 'big.jpg', 'size' => 3 * 1024 * 1024]);

        $ids = $this->fetchIds(['size_to_mb' => '1']);
        $this->assertContains($small->id, $ids);
        $this->assertNotContains($big->id, $ids);
    }

    // ----------- Этап 2: fuzzy для Product (C-8.5) -----------

    #[Test]
    public function fuzzy_off_does_not_find_via_translit(): void
    {
        $brand = Brand::create(['name' => 'Найк', 'slug' => 'naik-media-off']);
        $product = Product::factory()->create([
            'name' => 'Кроссовки беговые',
            'brand_id' => $brand->id,
        ]);
        $media = $this->makeMedia($product);

        $ids = $this->fetchIds(['search' => 'nayk']);
        $this->assertNotContains($media->id, $ids);
    }

    #[Test]
    public function fuzzy_on_finds_via_translit(): void
    {
        $this->enableFuzzy();

        $brand = Brand::create(['name' => 'Найк', 'slug' => 'naik-media-on']);
        $product = Product::factory()->create([
            'name' => 'Кроссовки беговые',
            'brand_id' => $brand->id,
        ]);
        $media = $this->makeMedia($product);

        $ids = $this->fetchIds(['search' => 'nayk']);
        $this->assertContains($media->id, $ids);
    }

    #[Test]
    public function exact_sku_search_works_without_fuzzy(): void
    {
        $product = Product::factory()->create(['sku' => 'AM90-001']);
        $media = $this->makeMedia($product);

        // Точный поиск по SKU работает через exact-match морфного поиска,
        // даже без fuzzy-флага.
        $ids = $this->fetchIds(['search' => 'AM90-001']);
        $this->assertContains($media->id, $ids);
    }

    #[Test]
    public function fuzzy_skipped_for_barcode_query(): void
    {
        $this->enableFuzzy();

        $product = Product::factory()->create(['name' => 'Товар без штрихкода']);
        $media = $this->makeMedia($product);

        // Запрос — 13 цифр, fuzzy не должен срабатывать; у товара нет такого штрихкода.
        $ids = $this->fetchIds(['search' => '4607012345678']);
        $this->assertNotContains($media->id, $ids);
    }
}
