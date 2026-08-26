<?php

namespace Tests\Feature\User;

use App\Models\Media;
use App\Models\Product;
use App\Models\ProductDefect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Регресс на утечку служебных медиа через медиатеку кабинета:
 * голосовые заметки CRM о клиентах (владелец User), фото уценки
 * (ProductDefect) и прочие непубличные вложения не должны быть видны
 * и скачиваемы партнёром ни через список, ни напрямую по id.
 */
class MediaLeakTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->client = User::factory()->create();
    }

    private function makeMedia(string $modelType, int $modelId, string $collection, string $fileName): Media
    {
        Storage::disk('public')->put($fileName, 'content');

        $media = Media::create([
            'model_type' => $modelType,
            'model_id' => $modelId,
            'uuid' => (string) Str::uuid(),
            'collection_name' => $collection,
            'name' => pathinfo($fileName, PATHINFO_FILENAME),
            'file_name' => $fileName,
            'mime_type' => 'video/webm',
            'disk' => 'public',
            'conversions_disk' => 'public',
            'size' => 1000,
            'manipulations' => [],
            'custom_properties' => [],
            'generated_conversions' => [],
            'responsive_images' => [],
            'order_column' => 1,
        ]);

        // Файл должен лежать по пути, который вернёт getPath() — кладём его туда.
        Storage::disk('public')->put($media->getPathRelativeToRoot(), 'content');
        Storage::disk('public')->put($media->getPath(), 'content');

        return $media;
    }

    #[Test]
    public function voice_notes_and_defect_photos_are_hidden_from_cabinet_media(): void
    {
        $otherClient = User::factory()->create();
        $voice = $this->makeMedia(User::class, $otherClient->id, 'crm-voice', 'voice-1.webm');
        $defect = $this->makeMedia(ProductDefect::class, 1, ProductDefect::MEDIA_COLLECTION, 'defect.jpg');
        $product = Product::factory()->create();
        $public = $this->makeMedia(Product::class, $product->id, 'main', 'photo.jpg');

        $ids = array_column(
            $this->actingAs($this->client)->getJson('/cabinet/media/api')->assertOk()->json('data'),
            'id',
        );

        $this->assertContains($public->id, $ids);
        $this->assertNotContains($voice->id, $ids);
        $this->assertNotContains($defect->id, $ids);

        // Фильтр по типу сущности не пробивает белый список.
        $ids = array_column(
            $this->actingAs($this->client)
                ->getJson('/cabinet/media/api?model_type='.urlencode(User::class))
                ->assertOk()->json('data'),
            'id',
        );
        $this->assertSame([], $ids);

        // Список типов на странице тоже без служебных владельцев.
        $this->actingAs($this->client)->get('/cabinet/media')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('modelTypes', fn ($types) => ! collect($types)->contains(User::class)
                    && ! collect($types)->contains(ProductDefect::class)));
    }

    #[Test]
    public function non_public_media_cannot_be_downloaded_by_id(): void
    {
        $otherClient = User::factory()->create();
        $voice = $this->makeMedia(User::class, $otherClient->id, 'crm-voice', 'voice-2.webm');
        $product = Product::factory()->create();
        $public = $this->makeMedia(Product::class, $product->id, 'main', 'photo2.jpg');

        $this->actingAs($this->client)->get("/cabinet/media/{$voice->id}/download")->assertNotFound();
        $this->actingAs($this->client)->get("/cabinet/media/{$public->id}/download")->assertOk();

        // Пакетное скачивание: служебный id молча отбрасывается; только служебные → 404.
        $this->actingAs($this->client)
            ->postJson('/cabinet/media/download-batch', ['ids' => [$voice->id]])
            ->assertNotFound();
    }
}
