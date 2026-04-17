<?php

namespace Tests\Feature\Api\Content;

use App\Models\Story;
use App\Models\StorySlide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoryTest extends TestCase
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

    // ── Stories CRUD ─────────────────────────────────────

    public function test_index_returns_stories_with_slide_count(): void
    {
        $story = Story::factory()->create();
        StorySlide::factory()->count(3)->create(['story_id' => $story->id]);

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/content/stories')
            ->assertOk()
            ->assertJsonPath('data.0.slides_count', 3);
    }

    public function test_store_creates_story(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/content/stories', [
                'name' => 'Новый сторис',
                'is_active' => true,
                'is_published' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Новый сторис');
    }

    public function test_show_includes_slides(): void
    {
        $story = Story::factory()->create();
        StorySlide::factory()->count(2)->create(['story_id' => $story->id]);

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/content/stories/{$story->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.slides');
    }

    public function test_destroy_cascade_deletes_slides(): void
    {
        $story = Story::factory()->create();
        StorySlide::factory()->count(3)->create(['story_id' => $story->id]);

        $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/content/stories/{$story->id}")
            ->assertNoContent();

        $this->assertDatabaseCount('story_slides', 0);
    }

    // ── Slides (nested) ─────────────────────────────────

    public function test_slides_index(): void
    {
        $story = Story::factory()->create();
        StorySlide::factory()->count(2)->create(['story_id' => $story->id]);

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/content/stories/{$story->id}/slides")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_slide_store(): void
    {
        $story = Story::factory()->create();

        $this->withHeaders($this->authHeaders())
            ->postJson("/api/content/stories/{$story->id}/slides", [
                'title' => 'Первый слайд',
                'content' => 'Текст слайда',
                'duration' => 5,
                'sort_order' => 0,
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Первый слайд');
    }

    public function test_slide_update(): void
    {
        $story = Story::factory()->create();
        $slide = StorySlide::factory()->create(['story_id' => $story->id, 'title' => 'Old']);

        $this->withHeaders($this->authHeaders())
            ->putJson("/api/content/stories/{$story->id}/slides/{$slide->id}", [
                'title' => 'New',
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'New');
    }

    public function test_slide_destroy(): void
    {
        $story = Story::factory()->create();
        $slide = StorySlide::factory()->create(['story_id' => $story->id]);

        $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/content/stories/{$story->id}/slides/{$slide->id}")
            ->assertNoContent();
    }

    public function test_slides_reorder(): void
    {
        $story = Story::factory()->create();
        $s1 = StorySlide::factory()->create(['story_id' => $story->id, 'sort_order' => 0]);
        $s2 = StorySlide::factory()->create(['story_id' => $story->id, 'sort_order' => 1]);

        $this->withHeaders($this->authHeaders())
            ->postJson("/api/content/stories/{$story->id}/slides/reorder", [
                'slides' => [
                    ['id' => $s1->id, 'sort_order' => 1],
                    ['id' => $s2->id, 'sort_order' => 0],
                ],
            ])
            ->assertOk();

        $this->assertEquals(1, $s1->fresh()->sort_order);
        $this->assertEquals(0, $s2->fresh()->sort_order);
    }

    public function test_slide_update_rejects_wrong_story(): void
    {
        $story1 = Story::factory()->create();
        $story2 = Story::factory()->create();
        $slide = StorySlide::factory()->create(['story_id' => $story2->id]);

        $this->withHeaders($this->authHeaders())
            ->putJson("/api/content/stories/{$story1->id}/slides/{$slide->id}", [
                'title' => 'hack',
            ])
            ->assertNotFound();
    }
}
