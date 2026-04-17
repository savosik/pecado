<?php

namespace Tests\Feature\Api\Content;

use App\Models\News;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Integration-тесты CRUD новостей через Content API.
 */
class NewsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test', ['*'])->plainTextToken;
    }

    protected function authHeaders(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    // ── INDEX ──────────────────────────────────────────────

    public function test_index_returns_paginated_news(): void
    {
        News::factory()->count(3)->create();

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/content/news');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['*' => ['id', 'title', 'slug', 'is_published', 'tags', 'images']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('meta.total', 3);
    }

    public function test_index_supports_search(): void
    {
        News::factory()->create(['title' => 'Spring Collection 2026']);
        News::factory()->create(['title' => 'Summer Discounts']);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/content/news?search=Spring');

        $response->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_index_filters_by_published(): void
    {
        News::factory()->create(['is_published' => true]);
        News::factory()->create(['is_published' => false]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/content/news?is_published=1');

        $response->assertOk()->assertJsonPath('meta.total', 1);
    }

    // ── SHOW ──────────────────────────────────────────────

    public function test_show_returns_single_news(): void
    {
        $news = News::factory()->create();

        $response = $this->withHeaders($this->authHeaders())
            ->getJson("/api/content/news/{$news->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $news->id)
            ->assertJsonPath('data.title', $news->title);
    }

    public function test_show_nonexistent_returns_404(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/content/news/99999');

        $response->assertNotFound();
    }

    // ── STORE ─────────────────────────────────────────────

    public function test_store_creates_news(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/content/news', [
                'title' => 'Тестовая новость',
                'slug' => 'testovaya-novost',
                'detailed_description' => '<p>Описание</p>',
                'is_published' => true,
                'tags' => ['тест', 'новинка'],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Тестовая новость')
            ->assertJsonPath('data.is_published', true);

        $this->assertDatabaseHas('news', ['title' => 'Тестовая новость']);
    }

    public function test_store_auto_generates_slug(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/content/news', [
                'title' => 'Автоматический слаг',
                'detailed_description' => '<p>test</p>',
            ]);

        $response->assertCreated();
        $this->assertNotEmpty($response->json('data.slug'));
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/content/news', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'detailed_description']);
    }

    public function test_store_with_media_file(): void
    {
        Storage::fake('media');

        $response = $this->withHeaders($this->authHeaders())
            ->post('/api/content/news', [
                'title' => 'С картинкой',
                'detailed_description' => '<p>test</p>',
                'list_item' => UploadedFile::fake()->image('cover.jpg', 800, 600),
            ]);

        $response->assertCreated();
        $this->assertNotNull($response->json('data.images.list_item'));
    }

    // ── UPDATE ────────────────────────────────────────────

    public function test_update_modifies_news(): void
    {
        $news = News::factory()->create(['title' => 'Старый']);

        $response = $this->withHeaders($this->authHeaders())
            ->putJson("/api/content/news/{$news->id}", [
                'title' => 'Новый',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.title', 'Новый');

        $this->assertDatabaseHas('news', ['id' => $news->id, 'title' => 'Новый']);
    }

    public function test_update_syncs_tags(): void
    {
        $news = News::factory()->create();
        $news->attachTags(['старый_тег']);

        $response = $this->withHeaders($this->authHeaders())
            ->putJson("/api/content/news/{$news->id}", [
                'tags' => ['новый_тег1', 'новый_тег2'],
            ]);

        $response->assertOk();
        $this->assertEquals(['новый_тег1', 'новый_тег2'], $response->json('data.tags'));
    }

    // ── DESTROY ───────────────────────────────────────────

    public function test_destroy_deletes_news(): void
    {
        $news = News::factory()->create();

        $response = $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/content/news/{$news->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('news', ['id' => $news->id]);
    }
}
