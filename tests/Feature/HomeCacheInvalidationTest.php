<?php

namespace Tests\Feature;

use App\Http\Controllers\User\ProductSelectionController;
use App\Models\Banner;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Product;
use App\Models\ProductSelection;
use App\Models\Region;
use App\Models\Story;
use App\Models\StorySlide;
use App\Support\HomeCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Проверяем, что любые изменения в моделях, которые попадают в кеши публички,
 * приводят к сбросу соответствующих ключей кеша.
 *
 * Если эти тесты падают — фронт показывает старые баннеры/сторис/подборки/меню
 * после публикации в админке, и пользователь видит результат только через 10 минут TTL.
 */
class HomeCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_banner_save_flushes_banner_cache(): void
    {
        $region = Region::factory()->create();

        Cache::put('user.banners.active.all', ['stale'], 600);
        Cache::put("user.banners.active.{$region->id}", ['stale'], 600);

        Banner::factory()->create();

        $this->assertNull(Cache::get('user.banners.active.all'));
        $this->assertNull(Cache::get("user.banners.active.{$region->id}"));
    }

    public function test_banner_delete_flushes_banner_cache(): void
    {
        $banner = Banner::factory()->create();

        Cache::put('user.banners.active.all', ['stale'], 600);
        $banner->delete();

        $this->assertNull(Cache::get('user.banners.active.all'));
    }

    public function test_story_save_flushes_story_cache(): void
    {
        $region = Region::factory()->create();

        Cache::put('user.stories.active.all', ['stale'], 600);
        Cache::put("user.stories.active.{$region->id}", ['stale'], 600);

        Story::factory()->create();

        $this->assertNull(Cache::get('user.stories.active.all'));
        $this->assertNull(Cache::get("user.stories.active.{$region->id}"));
    }

    public function test_story_slide_save_flushes_story_cache(): void
    {
        $story = Story::factory()->create();

        Cache::put('user.stories.active.all', ['stale'], 600);

        StorySlide::factory()->create(['story_id' => $story->id]);

        $this->assertNull(Cache::get('user.stories.active.all'));
    }

    public function test_product_selection_save_flushes_selection_cache(): void
    {
        Cache::put(ProductSelectionController::CACHE_KEY_SELECTIONS.'.all', ['stale'], 600);

        ProductSelection::factory()->create();

        $this->assertNull(Cache::get(ProductSelectionController::CACHE_KEY_SELECTIONS.'.all'));
    }

    public function test_menu_item_save_flushes_menu_cache(): void
    {
        Cache::put('menu.header', ['stale'], 3600);
        Cache::put('menu.footer', ['stale'], 3600);

        MenuItem::create([
            'title' => 'Test',
            'url' => '/test',
            'location' => 'header',
            'sort_order' => 0,
            'is_published' => true,
        ]);

        $this->assertNull(Cache::get('menu.header'));
        $this->assertNull(Cache::get('menu.footer'));
    }

    public function test_category_save_flushes_footer_categories_cache(): void
    {
        Cache::put('footer.categories', ['stale'], 3600);

        Category::factory()->create();

        $this->assertNull(Cache::get('footer.categories'));
    }

    public function test_product_create_flushes_selections_new_and_bestsellers(): void
    {
        Cache::put(ProductSelectionController::CACHE_KEY_SELECTIONS.'.all', ['stale'], 600);
        Cache::put(ProductSelectionController::CACHE_KEY_NEW.'.10', ['stale'], 600);
        Cache::put(ProductSelectionController::CACHE_KEY_BESTSELLERS.'.10', ['stale'], 600);

        Product::factory()->create();

        $this->assertNull(Cache::get(ProductSelectionController::CACHE_KEY_SELECTIONS.'.all'));
        $this->assertNull(Cache::get(ProductSelectionController::CACHE_KEY_NEW.'.10'));
        $this->assertNull(Cache::get(ProductSelectionController::CACHE_KEY_BESTSELLERS.'.10'));
    }

    public function test_product_update_of_irrelevant_field_does_not_flush_cache(): void
    {
        $product = Product::factory()->create();

        // Кеш заполняется после создания — чистим и кладём фейк.
        HomeCache::flushProductRelated();
        Cache::put(ProductSelectionController::CACHE_KEY_NEW.'.10', ['stale'], 600);
        Cache::put(ProductSelectionController::CACHE_KEY_BESTSELLERS.'.10', ['stale'], 600);
        Cache::put(ProductSelectionController::CACHE_KEY_SELECTIONS.'.all', ['stale'], 600);

        // stock_quantity нет в productToArray → кеш трогать не должны.
        $product->updateQuietly(['updated_at' => now()]);
        $product->touch();

        $this->assertSame(['stale'], Cache::get(ProductSelectionController::CACHE_KEY_NEW.'.10'));
        $this->assertSame(['stale'], Cache::get(ProductSelectionController::CACHE_KEY_BESTSELLERS.'.10'));
        $this->assertSame(['stale'], Cache::get(ProductSelectionController::CACHE_KEY_SELECTIONS.'.all'));
    }

    public function test_product_update_of_is_new_flushes_new_products_cache(): void
    {
        $product = Product::factory()->create(['is_new' => false]);

        HomeCache::flushProductRelated();
        Cache::put(ProductSelectionController::CACHE_KEY_NEW.'.10', ['stale'], 600);
        Cache::put(ProductSelectionController::CACHE_KEY_BESTSELLERS.'.10', ['stale'], 600);

        $product->update(['is_new' => true]);

        $this->assertNull(Cache::get(ProductSelectionController::CACHE_KEY_NEW.'.10'));
        // is_bestseller не менялся — кеш бестселлеров остался.
        $this->assertSame(['stale'], Cache::get(ProductSelectionController::CACHE_KEY_BESTSELLERS.'.10'));
    }

    public function test_flush_all_clears_every_known_cache(): void
    {
        $region = Region::factory()->create();

        Cache::put('user.banners.active.all', 'b', 600);
        Cache::put("user.banners.active.{$region->id}", 'b', 600);
        Cache::put('user.stories.active.all', 's', 600);
        Cache::put("user.stories.active.{$region->id}", 's', 600);
        Cache::put(ProductSelectionController::CACHE_KEY_SELECTIONS.'.all', 'p', 600);
        Cache::put(ProductSelectionController::CACHE_KEY_NEW.'.10', 'n', 600);
        Cache::put(ProductSelectionController::CACHE_KEY_BESTSELLERS.'.10', 'b', 600);
        Cache::put('footer.categories', 'f', 600);
        Cache::put('menu.header', 'mh', 600);
        Cache::put('menu.footer', 'mf', 600);

        HomeCache::flushAll();

        $this->assertNull(Cache::get('user.banners.active.all'));
        $this->assertNull(Cache::get("user.banners.active.{$region->id}"));
        $this->assertNull(Cache::get('user.stories.active.all'));
        $this->assertNull(Cache::get("user.stories.active.{$region->id}"));
        $this->assertNull(Cache::get(ProductSelectionController::CACHE_KEY_SELECTIONS.'.all'));
        $this->assertNull(Cache::get(ProductSelectionController::CACHE_KEY_NEW.'.10'));
        $this->assertNull(Cache::get(ProductSelectionController::CACHE_KEY_BESTSELLERS.'.10'));
        $this->assertNull(Cache::get('footer.categories'));
        $this->assertNull(Cache::get('menu.header'));
        $this->assertNull(Cache::get('menu.footer'));
    }
}
