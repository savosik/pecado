<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Product;
use App\Models\ProductSelection;
use App\Models\Promotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Флаг активности контента: выключенный контент скрыт на сайте.
 */
class ContentActiveFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_promotion_is_visible_but_inactive_returns_404(): void
    {
        $active = Promotion::factory()->create(['name' => 'Активная акция', 'is_active' => true]);
        $inactive = Promotion::factory()->create(['name' => 'Скрытая акция', 'is_active' => false]);

        $this->get(route('promotions.show', $active->slug))->assertOk();
        $this->get(route('promotions.show', $inactive->slug))->assertNotFound();
    }

    public function test_promotion_active_scope_excludes_inactive(): void
    {
        Promotion::factory()->create(['is_active' => true]);
        Promotion::factory()->create(['is_active' => false]);

        $this->assertSame(1, Promotion::query()->active()->count());
    }

    public function test_unpublished_page_returns_404(): void
    {
        $published = Page::factory()->create(['is_published' => true]);
        $hidden = Page::factory()->create(['is_published' => false]);

        $this->get(route('pages.show', $published->slug))->assertOk();
        $this->get(route('pages.show', $hidden->slug))->assertNotFound();
    }

    public function test_inactive_selection_page_returns_404(): void
    {
        $active = ProductSelection::factory()->create(['is_active' => true]);
        $inactive = ProductSelection::factory()->create(['is_active' => false]);

        $this->get('/collections/'.$active->slug)->assertOk();
        $this->get('/collections/'.$inactive->slug)->assertNotFound();
    }

    public function test_selection_active_scope_excludes_inactive(): void
    {
        ProductSelection::factory()->create(['is_active' => true]);
        ProductSelection::factory()->create(['is_active' => false]);

        $this->assertSame(1, ProductSelection::query()->active()->count());
    }

    public function test_product_card_hides_inactive_promotions(): void
    {
        $product = Product::factory()->create();
        $active = Promotion::factory()->create(['name' => 'Живая акция', 'is_active' => true]);
        $inactive = Promotion::factory()->create(['name' => 'Отключённая акция', 'is_active' => false]);
        $product->promotions()->attach([$active->id, $inactive->id]);

        $response = $this->getJson(route('api.products.show', $product->slug))->assertOk();

        $names = collect($response->json('promotions'))->pluck('name');
        $this->assertContains('Живая акция', $names);
        $this->assertNotContains('Отключённая акция', $names);
    }
}
