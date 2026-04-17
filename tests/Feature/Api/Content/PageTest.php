<?php

namespace Tests\Feature\Api\Content;

use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageTest extends TestCase
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

    public function test_index_returns_pages(): void
    {
        Page::factory()->count(3)->create();

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/content/pages')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    }

    public function test_store_creates_page(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/content/pages', [
                'title' => 'О компании',
                'content' => '<p>Текст страницы</p>',
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'О компании');
    }

    public function test_show_returns_page(): void
    {
        $page = Page::factory()->create();

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/content/pages/{$page->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $page->id);
    }

    public function test_update_modifies_page(): void
    {
        $page = Page::factory()->create(['title' => 'Old']);

        $this->withHeaders($this->authHeaders())
            ->putJson("/api/content/pages/{$page->id}", ['title' => 'New'])
            ->assertOk()
            ->assertJsonPath('data.title', 'New');
    }

    public function test_destroy_deletes_page(): void
    {
        $page = Page::factory()->create();

        $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/content/pages/{$page->id}")
            ->assertNoContent();
    }
}
