<?php

namespace Tests\Feature\Api\Content;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductMediaTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test', ['*'])->plainTextToken;
    }

    protected function authHeaders(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    public function test_requires_authentication(): void
    {
        $product = Product::factory()->create();

        $this->postJson("/api/content/products/{$product->id}/media", [
            'collection' => 'main',
            'image' => UploadedFile::fake()->image('main.jpg'),
        ])->assertUnauthorized();
    }

    public function test_upload_main_image(): void
    {
        $product = Product::factory()->create();

        $this->withHeaders($this->authHeaders())
            ->postJson("/api/content/products/{$product->id}/media", [
                'collection' => 'main',
                'image' => UploadedFile::fake()->image('main.jpg'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.collection', 'main')
            ->assertJsonCount(1, 'data.added')
            ->assertJsonCount(1, 'data.main');

        $this->assertCount(1, $product->fresh()->getMedia('main'));
    }

    public function test_main_image_replaces_previous(): void
    {
        $product = Product::factory()->create();

        $this->withHeaders($this->authHeaders())
            ->postJson("/api/content/products/{$product->id}/media", [
                'collection' => 'main',
                'image' => UploadedFile::fake()->image('first.jpg'),
            ])->assertCreated();

        $this->withHeaders($this->authHeaders())
            ->postJson("/api/content/products/{$product->id}/media", [
                'collection' => 'main',
                'image' => UploadedFile::fake()->image('second.jpg'),
            ])->assertCreated();

        $main = $product->fresh()->getMedia('main');
        $this->assertCount(1, $main);
        $this->assertEquals('second.jpg', $main->first()->file_name);
    }

    public function test_main_rejects_multiple_images(): void
    {
        $product = Product::factory()->create();

        $this->withHeaders($this->authHeaders())
            ->postJson("/api/content/products/{$product->id}/media", [
                'collection' => 'main',
                'images' => [
                    UploadedFile::fake()->image('a.jpg'),
                    UploadedFile::fake()->image('b.jpg'),
                ],
            ])
            ->assertStatus(422);

        $this->assertCount(0, $product->fresh()->getMedia('main'));
    }

    public function test_upload_multiple_additional_images(): void
    {
        $product = Product::factory()->create();

        $this->withHeaders($this->authHeaders())
            ->postJson("/api/content/products/{$product->id}/media", [
                'collection' => 'additional',
                'images' => [
                    UploadedFile::fake()->image('a.jpg'),
                    UploadedFile::fake()->image('b.jpg'),
                    UploadedFile::fake()->image('c.jpg'),
                ],
            ])
            ->assertCreated()
            ->assertJsonCount(3, 'data.added')
            ->assertJsonCount(3, 'data.additional');

        $this->assertCount(3, $product->fresh()->getMedia('additional'));
    }

    public function test_additional_images_append(): void
    {
        $product = Product::factory()->create();
        $product->addMedia(UploadedFile::fake()->image('existing.jpg'))->toMediaCollection('additional');

        $this->withHeaders($this->authHeaders())
            ->postJson("/api/content/products/{$product->id}/media", [
                'collection' => 'additional',
                'image' => UploadedFile::fake()->image('new.jpg'),
            ])
            ->assertCreated();

        $this->assertCount(2, $product->fresh()->getMedia('additional'));
    }

    public function test_invalid_collection_rejected(): void
    {
        $product = Product::factory()->create();

        $this->withHeaders($this->authHeaders())
            ->postJson("/api/content/products/{$product->id}/media", [
                'collection' => 'video',
                'image' => UploadedFile::fake()->image('a.jpg'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('collection');
    }

    public function test_no_image_source_rejected(): void
    {
        $product = Product::factory()->create();

        $this->withHeaders($this->authHeaders())
            ->postJson("/api/content/products/{$product->id}/media", [
                'collection' => 'additional',
            ])
            ->assertStatus(422);
    }

    public function test_non_image_file_rejected(): void
    {
        $product = Product::factory()->create();

        $this->withHeaders($this->authHeaders())
            ->postJson("/api/content/products/{$product->id}/media", [
                'collection' => 'main',
                'image' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    }

    public function test_index_lists_media(): void
    {
        $product = Product::factory()->create();
        $product->addMedia(UploadedFile::fake()->image('main.jpg'))->toMediaCollection('main');
        $product->addMedia(UploadedFile::fake()->image('add1.jpg'))->toMediaCollection('additional');
        $product->addMedia(UploadedFile::fake()->image('add2.jpg'))->toMediaCollection('additional');

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/content/products/{$product->id}/media")
            ->assertOk()
            ->assertJsonCount(1, 'data.main')
            ->assertJsonCount(2, 'data.additional');
    }

    public function test_destroy_removes_media(): void
    {
        $product = Product::factory()->create();
        $media = $product->addMedia(UploadedFile::fake()->image('add.jpg'))->toMediaCollection('additional');

        $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/content/products/{$product->id}/media/{$media->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertCount(0, $product->fresh()->getMedia('additional'));
    }

    public function test_destroy_foreign_media_returns_404(): void
    {
        $product = Product::factory()->create();
        $other = Product::factory()->create();
        $media = $other->addMedia(UploadedFile::fake()->image('add.jpg'))->toMediaCollection('additional');

        $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/content/products/{$product->id}/media/{$media->id}")
            ->assertNotFound();
    }
}
