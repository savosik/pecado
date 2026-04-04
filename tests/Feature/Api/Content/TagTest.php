<?php

namespace Tests\Feature\Api\Content;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Tags\Tag;
use Tests\TestCase;

class TagTest extends TestCase
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

    public function test_index_returns_tags(): void
    {
        Tag::findOrCreate('тег1');
        Tag::findOrCreate('тег2');
        Tag::findOrCreate('тег3');

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/content/tags')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    }

    public function test_index_search(): void
    {
        Tag::findOrCreate('весна');
        Tag::findOrCreate('лето');

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/content/tags?search=вес')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_store_creates_tag(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/content/tags', [
                'name' => 'новый_тег',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'новый_тег');
    }

    public function test_store_idempotent(): void
    {
        Tag::findOrCreate('existing');

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/content/tags', ['name' => 'existing'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'existing');

        // Тег не должен дублироваться
        $this->assertEquals(1, Tag::containing('existing')->count());
    }
}
