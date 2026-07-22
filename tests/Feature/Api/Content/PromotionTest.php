<?php

namespace Tests\Feature\Api\Content;

use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionTest extends TestCase
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

    public function test_index_returns_promotions(): void
    {
        Promotion::factory()->count(2)->create();

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/content/promotions')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_store_creates_promotion(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/content/promotions', [
                'name' => 'Летняя акция',
                'description' => '<p>Скидки до 50%</p>',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Летняя акция');
    }

    public function test_store_with_products(): void
    {
        $products = Product::factory()->count(3)->create();

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/content/promotions', [
                'name' => 'С товарами',
                'product_ids' => $products->pluck('id')->toArray(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.products_count', 3);
    }

    public function test_sync_products(): void
    {
        $promotion = Promotion::factory()->create();
        $products = Product::factory()->count(5)->create();

        $this->withHeaders($this->authHeaders())
            ->postJson("/api/content/promotions/{$promotion->id}/products", [
                'product_ids' => $products->pluck('id')->toArray(),
            ])
            ->assertOk()
            ->assertJsonPath('products_count', 5);
    }

    public function test_show_includes_products(): void
    {
        $promotion = Promotion::factory()->create();
        $products = Product::factory()->count(2)->create();
        $promotion->products()->sync($products->pluck('id'));

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/content/promotions/{$promotion->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.products');
    }

    public function test_store_persists_is_active_flag(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/content/promotions', [
                'name' => 'Скрытая акция',
                'is_active' => false,
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('promotions', [
            'name' => 'Скрытая акция',
            'is_active' => false,
        ]);
    }

    public function test_update_toggles_is_active_flag(): void
    {
        $promotion = Promotion::factory()->create(['is_active' => true]);

        $this->withHeaders($this->authHeaders())
            ->putJson("/api/content/promotions/{$promotion->id}", [
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($promotion->fresh()->is_active);
    }

    public function test_destroy_deletes_promotion(): void
    {
        $promotion = Promotion::factory()->create();

        $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/content/promotions/{$promotion->id}")
            ->assertNoContent();
    }
}
