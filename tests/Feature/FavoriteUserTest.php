<?php

namespace Tests\Feature;

use App\Models\Favorite;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FavoriteUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_favorites_page(): void
    {
        $response = $this->get('/favorites');
        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_favorites_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/favorites');
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('User/Favorites/Index'));
    }

    public function test_favorites_page_shows_favorited_products(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        Favorite::create([
            'user_id'    => $user->id,
            'product_id' => $product->id,
        ]);

        $response = $this->actingAs($user)->get('/favorites');
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('User/Favorites/Index')
            ->has('favorites.data', 1)
            ->where('favorites.total', 1)
        );
    }

    public function test_toggle_adds_product_to_favorites(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        $response = $this->actingAs($user)
            ->postJson("/api/favorites/{$product->id}/toggle");

        $response->assertStatus(200)
            ->assertJson(['added' => true]);

        $this->assertDatabaseHas('favorites', [
            'user_id'    => $user->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_toggle_removes_product_from_favorites(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        Favorite::create([
            'user_id'    => $user->id,
            'product_id' => $product->id,
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/favorites/{$product->id}/toggle");

        $response->assertStatus(200)
            ->assertJson(['removed' => true]);

        $this->assertDatabaseMissing('favorites', [
            'user_id'    => $user->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_ids_returns_favorite_product_ids(): void
    {
        $user = User::factory()->create();
        $product1 = Product::factory()->create();
        $product2 = Product::factory()->create();

        Favorite::create(['user_id' => $user->id, 'product_id' => $product1->id]);
        Favorite::create(['user_id' => $user->id, 'product_id' => $product2->id]);

        $response = $this->actingAs($user)->getJson('/api/favorites/ids');

        $response->assertStatus(200)
            ->assertJsonStructure(['product_ids'])
            ->assertJsonCount(2, 'product_ids');
    }

    public function test_guest_cannot_access_favorites_api(): void
    {
        $product = Product::factory()->create();

        $this->postJson("/api/favorites/{$product->id}/toggle")
            ->assertStatus(401);

        $this->getJson('/api/favorites/ids')
            ->assertStatus(401);
    }
}
