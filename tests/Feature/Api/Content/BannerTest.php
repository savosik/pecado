<?php

namespace Tests\Feature\Api\Content;

use App\Models\Banner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BannerTest extends TestCase
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

    public function test_index_returns_banners(): void
    {
        Banner::factory()->count(2)->create();

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/content/banners')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_store_creates_banner(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/content/banners', [
                'title' => 'Главный баннер',
                'is_active' => true,
                'sort_order' => 0,
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Главный баннер');
    }

    public function test_store_creates_banner_with_link_url(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/content/banners', [
                'title' => 'Баннер с URL',
                'link_url' => 'https://example.com/promo',
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.link_url', 'https://example.com/promo');

        $this->assertDatabaseHas('banners', [
            'title' => 'Баннер с URL',
            'link_url' => 'https://example.com/promo',
        ]);
    }

    public function test_update_sets_link_url(): void
    {
        $banner = Banner::factory()->create(['link_url' => null]);

        $this->withHeaders($this->authHeaders())
            ->putJson("/api/content/banners/{$banner->id}", ['link_url' => '/sale'])
            ->assertOk()
            ->assertJsonPath('data.link_url', '/sale');
    }

    public function test_show_returns_banner(): void
    {
        $banner = Banner::factory()->create();

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/content/banners/{$banner->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $banner->id);
    }

    public function test_update_modifies_banner(): void
    {
        $banner = Banner::factory()->create(['title' => 'Old']);

        $this->withHeaders($this->authHeaders())
            ->putJson("/api/content/banners/{$banner->id}", ['title' => 'New'])
            ->assertOk()
            ->assertJsonPath('data.title', 'New');
    }

    public function test_destroy_deletes_banner(): void
    {
        $banner = Banner::factory()->create();

        $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/content/banners/{$banner->id}")
            ->assertNoContent();
    }
}
