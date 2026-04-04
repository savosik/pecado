<?php

namespace Tests\Feature\Api\Content;

use App\Models\Brand;
use App\Models\BrandStory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandStoryTest extends TestCase
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

    public function test_index_returns_brand_stories(): void
    {
        BrandStory::factory()->count(2)->create();

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/content/brand-stories')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_store_creates_brand_story(): void
    {
        $brand = Brand::factory()->create();

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/content/brand-stories', [
                'title' => 'История бренда',
                'short_description' => 'Кратко',
                'detailed_description' => '<p>Подробно</p>',
                'brand_id' => $brand->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'История бренда')
            ->assertJsonPath('data.brand.id', $brand->id);
    }

    public function test_show_includes_brand(): void
    {
        $brandStory = BrandStory::factory()->create();

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/content/brand-stories/{$brandStory->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['brand' => ['id', 'name']]]);
    }

    public function test_filter_by_brand_id(): void
    {
        $brand = Brand::factory()->create();
        BrandStory::factory()->count(2)->create(['brand_id' => $brand->id]);
        BrandStory::factory()->create();

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/content/brand-stories?brand_id={$brand->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_destroy_deletes_brand_story(): void
    {
        $brandStory = BrandStory::factory()->create();

        $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/content/brand-stories/{$brandStory->id}")
            ->assertNoContent();
    }
}
