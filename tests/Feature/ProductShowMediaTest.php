<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class ProductShowMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_array_includes_large_and_thumb_keys_for_image_items(): void
    {
        Storage::fake('public');
        $product = Product::factory()->create();
        $product->addMedia(UploadedFile::fake()->image('main.jpg', 2000, 3000))
            ->toMediaCollection('main');

        $response = $this->getJson("/api/products/{$product->slug}");

        $response->assertOk()
            ->assertJsonStructure([
                'media' => [
                    ['url', 'large', 'thumb', 'type'],
                ],
            ])
            ->assertJsonPath('media.0.type', 'image');

        $payload = $response->json('media.0');
        $this->assertNotEmpty($payload['large']);
        $this->assertNotEmpty($payload['thumb']);
        $this->assertNotSame($payload['url'], $payload['large']);
        $this->assertStringContainsString('/conversions/', $payload['large']);
        $this->assertStringContainsString('/conversions/', $payload['thumb']);
    }

    public function test_media_array_falls_back_to_original_when_conversion_missing(): void
    {
        Storage::fake('public');
        $product = Product::factory()->create();
        $media = $product->addMedia(UploadedFile::fake()->image('main.jpg', 2000, 3000))
            ->toMediaCollection('main');

        // Симулируем состояние «генерация ещё не завершилась»: thumb готов,
        // large — нет. Контроллер должен подменить large на оригинал.
        Media::query()
            ->where('id', $media->id)
            ->update(['generated_conversions' => json_encode(['thumb' => true, 'large' => false])]);

        $response = $this->getJson("/api/products/{$product->slug}");

        $response->assertOk();
        $item = $response->json('media.0');
        $this->assertSame($item['url'], $item['large'], 'large должно деградировать к оригиналу когда conversion не готова');
        $this->assertNotSame($item['url'], $item['thumb'], 'thumb сгенерирован — должен быть отличный от оригинала URL');
    }

    public function test_video_item_has_only_url_field(): void
    {
        Storage::fake('public');
        $product = Product::factory()->create();

        // Минимальный валидный MP4-заголовок (ftyp box). Достаточно для прохождения
        // mime-чека fileinfo и попадания в коллекцию video.
        $mp4Bytes = "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 64);
        $product->addMediaFromString($mp4Bytes)
            ->usingFileName('promo.mp4')
            ->usingName('promo')
            ->toMediaCollection('video');

        $response = $this->getJson("/api/products/{$product->slug}");

        $response->assertOk();
        $videoItems = collect($response->json('media'))->where('type', 'video')->values();
        $this->assertCount(1, $videoItems);
        $this->assertArrayHasKey('url', $videoItems[0]);
        $this->assertArrayNotHasKey('large', $videoItems[0]);
        $this->assertArrayNotHasKey('thumb', $videoItems[0]);
    }
}
