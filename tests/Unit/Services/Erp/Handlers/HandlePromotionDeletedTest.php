<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\ErpPromotion;
use App\Models\Product;
use App\Services\Erp\Handlers\HandlePromotionCreated;
use App\Services\Erp\Handlers\HandlePromotionDeleted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandlePromotionDeletedTest extends TestCase
{
    use RefreshDatabase;

    private HandlePromotionCreated $create;

    private HandlePromotionDeleted $delete;

    protected function setUp(): void
    {
        parent::setUp();
        $this->create = app(HandlePromotionCreated::class);
        $this->delete = app(HandlePromotionDeleted::class);
    }

    #[Test]
    public function removes_promotion_and_clears_flag(): void
    {
        $product = Product::factory()->create(['external_id' => 'prod-del', 'is_new' => false]);

        $this->create->handle([
            'event' => 'promotion.created',
            'uuid' => 'promo-del',
            'type' => 'new',
            'items' => [['uuid' => 'prod-del']],
        ]);
        $this->assertTrue((bool) $product->fresh()->is_new);

        $this->delete->handle([
            'event' => 'promotion.deleted',
            'uuid' => 'promo-del',
        ]);

        $this->assertDatabaseMissing('erp_promotions', ['uuid' => 'promo-del']);
        $this->assertDatabaseMissing('erp_promotion_product', ['product_id' => $product->id]);
        $this->assertFalse((bool) $product->fresh()->is_new);
    }

    #[Test]
    public function preserves_flag_if_product_is_in_other_promotion_of_same_type(): void
    {
        $product = Product::factory()->create(['external_id' => 'prod-keep', 'is_bestseller' => false]);

        $this->create->handle([
            'event' => 'promotion.created',
            'uuid' => 'promo-keep-a',
            'type' => 'bestseller',
            'items' => [['uuid' => 'prod-keep']],
        ]);
        $this->create->handle([
            'event' => 'promotion.created',
            'uuid' => 'promo-keep-b',
            'type' => 'bestseller',
            'items' => [['uuid' => 'prod-keep']],
        ]);

        $this->delete->handle([
            'event' => 'promotion.deleted',
            'uuid' => 'promo-keep-a',
        ]);

        $this->assertTrue((bool) $product->fresh()->is_bestseller);
        $this->assertDatabaseMissing('erp_promotions', ['uuid' => 'promo-keep-a']);
        $this->assertDatabaseHas('erp_promotions', ['uuid' => 'promo-keep-b']);
    }

    #[Test]
    public function missing_promotion_is_noop(): void
    {
        $this->delete->handle([
            'event' => 'promotion.deleted',
            'uuid' => 'promo-never-existed',
        ]);

        $this->assertEquals(0, ErpPromotion::count());
    }
}
