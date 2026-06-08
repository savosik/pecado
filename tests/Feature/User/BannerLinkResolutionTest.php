<?php

namespace Tests\Feature\User;

use App\Http\Controllers\User\BannerController;
use App\Models\Banner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class BannerLinkResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_link_url_is_used_for_public_banner(): void
    {
        Banner::factory()->create([
            'link_url' => 'https://example.com/promo',
            'is_active' => true,
        ]);

        $banners = BannerController::getCachedBanners(null);

        $this->assertSame('https://example.com/promo', $banners[0]['link_url']);
    }

    public function test_link_url_has_priority_over_linkable(): void
    {
        $banner = Banner::factory()->create([
            'link_url' => '/sale',
            'linkable_type' => \App\Models\Product::class,
            'linkable_id' => 999, // несуществующая сущность — не должна резолвиться
            'is_active' => true,
        ]);

        $banners = BannerController::getCachedBanners(null);

        $this->assertSame('/sale', $banners[0]['link_url']);
    }

    public function test_null_link_url_falls_back_to_no_link_without_linkable(): void
    {
        Banner::factory()->create([
            'link_url' => null,
            'is_active' => true,
        ]);

        $banners = BannerController::getCachedBanners(null);

        $this->assertNull($banners[0]['link_url']);
    }
}
