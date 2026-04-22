<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Article;
use App\Models\Brand;
use App\Models\News;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CabinetMediaSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->user = User::factory()->create([
            'status' => UserStatus::ACTIVE,
            'must_change_password' => false,
        ]);
    }

    #[Test]
    public function exact_sku_search_does_not_leak_longer_codes(): void
    {
        $short = Product::factory()->create(['sku' => '550001', 'name' => 'Short one']);
        $long = Product::factory()->create(['sku' => '5500100', 'name' => 'Long one']);

        $shortMedia = $short->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaCollection('additional');
        $long->addMedia(UploadedFile::fake()->image('b.jpg'))->toMediaCollection('additional');

        $response = $this->actingAs($this->user)->getJson('/cabinet/media/api?search=550001');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($shortMedia->id, $ids);
        $this->assertSame([$shortMedia->id], $ids, 'По точному артикулу 550001 не должны появляться медиа товара с sku 5500100');
    }

    #[Test]
    public function search_by_brand_name_finds_related_product_media(): void
    {
        $brand = Brand::create(['name' => 'Satisfyer', 'slug' => 'satisfyer']);
        $product = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'Pro 2']);
        $other = Product::factory()->create(['name' => 'Unrelated item']);

        $media = $product->addMedia(UploadedFile::fake()->image('pro2.jpg'))->toMediaCollection('additional');
        $other->addMedia(UploadedFile::fake()->image('other.jpg'))->toMediaCollection('additional');

        $response = $this->actingAs($this->user)->getJson('/cabinet/media/api?search=satisfyer');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($media->id, $ids);
        $this->assertCount(1, $ids);
    }

    #[Test]
    public function search_by_additional_barcode_finds_product_media(): void
    {
        $product = Product::factory()->create(['name' => 'Some product']);
        ProductBarcode::create(['product_id' => $product->id, 'barcode' => '4607002123456']);

        $media = $product->addMedia(UploadedFile::fake()->image('box.jpg'))->toMediaCollection('additional');

        $response = $this->actingAs($this->user)->getJson('/cabinet/media/api?search=4607002123456');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame([$media->id], $ids);
    }

    #[Test]
    public function search_by_article_title_finds_article_media(): void
    {
        $article = Article::create([
            'title' => 'Обзор новинок Satisfyer',
            'slug' => 'obzor-satisfyer',
            'short_description' => 'short',
            'detailed_description' => 'detail',
            'is_published' => true,
        ]);
        $product = Product::factory()->create(['name' => 'Unrelated']);

        $articleMedia = $article->addMedia(UploadedFile::fake()->image('cover.jpg'))->toMediaCollection('cover');
        $product->addMedia(UploadedFile::fake()->image('p.jpg'))->toMediaCollection('additional');

        $response = $this->actingAs($this->user)->getJson('/cabinet/media/api?search=satisfyer');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($articleMedia->id, $ids);
    }

    #[Test]
    public function model_type_filter_limits_search_scope(): void
    {
        $brand = Brand::create(['name' => 'Satisfyer', 'slug' => 'satisfyer-b']);
        $product = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'P']);
        $news = News::create([
            'title' => 'Новинки Satisfyer',
            'slug' => 'novinki-satisfyer',
            'detailed_description' => 'detail',
            'is_published' => true,
        ]);

        $productMedia = $product->addMedia(UploadedFile::fake()->image('p.jpg'))->toMediaCollection('additional');
        $newsMedia = $news->addMedia(UploadedFile::fake()->image('n.jpg'))->toMediaCollection('cover');

        $response = $this->actingAs($this->user)
            ->getJson('/cabinet/media/api?search=satisfyer&model_type='.urlencode(Product::class));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($productMedia->id, $ids);
        $this->assertNotContains($newsMedia->id, $ids, 'Фильтр "Тип сущности = Товар" не должен отдавать медиа новостей');
    }

    #[Test]
    public function search_by_media_file_name(): void
    {
        $product = Product::factory()->create(['name' => 'Some']);
        $match = $product->addMedia(UploadedFile::fake()->image('satisfyer-pro-2.jpg'))->toMediaCollection('additional');
        $product->addMedia(UploadedFile::fake()->image('other.jpg'))->toMediaCollection('additional');

        $response = $this->actingAs($this->user)->getJson('/cabinet/media/api?search=satisfyer-pro');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($match->id, $ids);
    }
}
