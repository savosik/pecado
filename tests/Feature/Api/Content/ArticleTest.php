<?php

namespace Tests\Feature\Api\Content;

use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticleTest extends TestCase
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

    public function test_index_returns_articles(): void
    {
        Article::factory()->count(3)->create();

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/content/articles')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    }

    public function test_store_creates_article(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/content/articles', [
                'title' => 'Обзор новинок',
                'short_description' => 'Краткое описание',
                'detailed_description' => '<p>Полное описание</p>',
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Обзор новинок');
    }

    public function test_show_returns_article(): void
    {
        $article = Article::factory()->create();

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/content/articles/{$article->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $article->id);
    }

    public function test_update_modifies_article(): void
    {
        $article = Article::factory()->create(['title' => 'Old']);

        $this->withHeaders($this->authHeaders())
            ->putJson("/api/content/articles/{$article->id}", ['title' => 'New'])
            ->assertOk()
            ->assertJsonPath('data.title', 'New');
    }

    public function test_destroy_deletes_article(): void
    {
        $article = Article::factory()->create();

        $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/content/articles/{$article->id}")
            ->assertNoContent();
    }
}
