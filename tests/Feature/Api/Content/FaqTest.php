<?php

namespace Tests\Feature\Api\Content;

use App\Models\Faq;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FaqTest extends TestCase
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

    public function test_index_returns_faqs(): void
    {
        Faq::factory()->count(3)->create();

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/content/faqs')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    }

    public function test_store_creates_faq(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/content/faqs', [
                'title' => 'Как оформить заказ?',
                'content' => '<p>Инструкция...</p>',
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Как оформить заказ?');
    }

    public function test_show_returns_faq(): void
    {
        $faq = Faq::factory()->create();

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/content/faqs/{$faq->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $faq->id);
    }

    public function test_update_modifies_faq(): void
    {
        $faq = Faq::factory()->create(['title' => 'Старый']);

        $this->withHeaders($this->authHeaders())
            ->putJson("/api/content/faqs/{$faq->id}", ['title' => 'Новый'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Новый');
    }

    public function test_destroy_deletes_faq(): void
    {
        $faq = Faq::factory()->create();

        $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/content/faqs/{$faq->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('faqs', ['id' => $faq->id]);
    }
}
