<?php

namespace Tests\Feature;

use App\Media\EncodedPathUrlGenerator;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Путь медиа товара строится из кода 1С (кириллица, `products-media/УТ-…/…`).
 * URL в фидах/экспортах должен быть percent-encoded (RFC 3986), иначе строгие
 * HTTP-клиенты не могут скачать фото.
 */
class EncodedMediaUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_config_uses_encoded_url_generator(): void
    {
        $this->assertSame(
            EncodedPathUrlGenerator::class,
            config('media-library.url_generator'),
        );
    }

    public function test_cyrillic_code_path_is_percent_encoded_in_url(): void
    {
        Storage::fake('public');

        $product = Product::factory()->create(['code' => 'УТ-00007791']);

        $product->addMedia(UploadedFile::fake()->image('main.jpg'))
            ->withCustomProperties(['product_code' => $product->code])
            ->toMediaCollection('main');

        $url = $product->getFirstMedia('main')->getFullUrl();

        // Кириллица закодирована, «сырых» байтов в URL нет.
        $this->assertStringContainsString('products-media/%D0%A3%D0%A2-00007791/main/', $url);
        $this->assertStringNotContainsString('УТ-00007791', $url);

        // Слэши-разделители и расширение файла не пострадали.
        $this->assertStringContainsString('/main/', $url);
        $this->assertStringEndsWith('.jpg', $url);
    }

    public function test_ascii_only_path_is_left_intact(): void
    {
        Storage::fake('public');

        $product = Product::factory()->create(['code' => 'ABC-123']);

        $product->addMedia(UploadedFile::fake()->image('main.jpg'))
            ->withCustomProperties(['product_code' => $product->code])
            ->toMediaCollection('main');

        $url = $product->getFirstMedia('main')->getFullUrl();

        // ASCII-код остаётся как есть — без лишнего percent-encoding.
        $this->assertStringContainsString('products-media/ABC-123/main/', $url);
        $this->assertStringNotContainsString('%', $url);
    }
}
