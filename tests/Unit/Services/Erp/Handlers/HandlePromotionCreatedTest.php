<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\ErpPromotion;
use App\Models\Product;
use App\Services\Erp\Handlers\HandlePromotionCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandlePromotionCreatedTest extends TestCase
{
    use RefreshDatabase;

    private HandlePromotionCreated $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = app(HandlePromotionCreated::class);
    }

    #[Test]
    public function creates_promotion_and_sets_is_new_flag(): void
    {
        $p1 = Product::factory()->create([
            'external_id' => 'prod-uuid-1',
            'is_new' => false,
            'is_bestseller' => false,
            'is_liquidation' => false,
        ]);
        $p2 = Product::factory()->create([
            'external_id' => 'prod-uuid-2',
            'is_new' => false,
            'is_bestseller' => false,
            'is_liquidation' => false,
        ]);

        $this->handler->handle([
            'event' => 'promotion.created',
            'message_id' => 'msg-promo-1',
            'uuid' => 'promo-uuid-new-1',
            'type' => 'new',
            'items' => [
                ['uuid' => 'prod-uuid-1'],
                ['uuid' => 'prod-uuid-2'],
            ],
        ]);

        $this->assertDatabaseHas('erp_promotions', [
            'uuid' => 'promo-uuid-new-1',
            'type' => 'new',
        ]);

        $promotion = ErpPromotion::where('uuid', 'promo-uuid-new-1')->first();
        $this->assertCount(2, $promotion->products);

        $this->assertTrue((bool) $p1->fresh()->is_new);
        $this->assertTrue((bool) $p2->fresh()->is_new);
        $this->assertFalse((bool) $p1->fresh()->is_bestseller);
        $this->assertFalse((bool) $p2->fresh()->is_liquidation);
    }

    #[Test]
    public function sets_is_bestseller_flag_for_type_bestseller(): void
    {
        $product = Product::factory()->create(['external_id' => 'prod-bs', 'is_bestseller' => false]);

        $this->handler->handle([
            'event' => 'promotion.created',
            'uuid' => 'promo-uuid-bs',
            'type' => 'bestseller',
            'items' => [['uuid' => 'prod-bs']],
        ]);

        $this->assertTrue((bool) $product->fresh()->is_bestseller);
    }

    #[Test]
    public function sets_is_liquidation_flag_for_type_liquidation(): void
    {
        $product = Product::factory()->create(['external_id' => 'prod-liq', 'is_liquidation' => false]);

        $this->handler->handle([
            'event' => 'promotion.created',
            'uuid' => 'promo-uuid-liq',
            'type' => 'liquidation',
            'items' => [['uuid' => 'prod-liq']],
        ]);

        $this->assertTrue((bool) $product->fresh()->is_liquidation);
    }

    #[Test]
    public function ignores_unknown_product_uuids(): void
    {
        $known = Product::factory()->create(['external_id' => 'prod-known', 'is_new' => false]);

        $this->handler->handle([
            'event' => 'promotion.created',
            'uuid' => 'promo-mixed',
            'type' => 'new',
            'items' => [
                ['uuid' => 'prod-known'],
                ['uuid' => 'unknown-uuid'],
            ],
        ]);

        $promotion = ErpPromotion::where('uuid', 'promo-mixed')->first();
        $this->assertCount(1, $promotion->products);
        $this->assertTrue((bool) $known->fresh()->is_new);
    }

    #[Test]
    public function is_idempotent_on_repeated_call(): void
    {
        $p1 = Product::factory()->create(['external_id' => 'prod-idem-1']);
        $p2 = Product::factory()->create(['external_id' => 'prod-idem-2']);

        $payload = [
            'event' => 'promotion.created',
            'uuid' => 'promo-idem',
            'type' => 'new',
            'items' => [
                ['uuid' => 'prod-idem-1'],
                ['uuid' => 'prod-idem-2'],
            ],
        ];

        $this->handler->handle($payload);
        $this->handler->handle($payload);

        $this->assertCount(1, ErpPromotion::where('uuid', 'promo-idem')->get());
        $promotion = ErpPromotion::where('uuid', 'promo-idem')->first();
        $this->assertCount(2, $promotion->products);
        $this->assertTrue((bool) $p1->fresh()->is_new);
        $this->assertTrue((bool) $p2->fresh()->is_new);
    }

    #[Test]
    public function rejects_unknown_type(): void
    {
        $product = Product::factory()->create(['external_id' => 'prod-x', 'is_new' => false]);

        $this->handler->handle([
            'event' => 'promotion.created',
            'uuid' => 'promo-bad',
            'type' => 'unsupported',
            'items' => [['uuid' => 'prod-x']],
        ]);

        $this->assertDatabaseMissing('erp_promotions', ['uuid' => 'promo-bad']);
        $this->assertFalse((bool) $product->fresh()->is_new);
    }
}
