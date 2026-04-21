<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\ErpPromotion;
use App\Models\Product;
use App\Services\Erp\Handlers\HandlePromotionCreated;
use App\Services\Erp\Handlers\HandlePromotionUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandlePromotionUpdatedTest extends TestCase
{
    use RefreshDatabase;

    private HandlePromotionCreated $create;

    private HandlePromotionUpdated $update;

    protected function setUp(): void
    {
        parent::setUp();
        $this->create = app(HandlePromotionCreated::class);
        $this->update = app(HandlePromotionUpdated::class);
    }

    #[Test]
    public function replaces_items_and_clears_flag_for_removed_product(): void
    {
        $p1 = Product::factory()->create(['external_id' => 'prod-upd-1']);
        $p2 = Product::factory()->create(['external_id' => 'prod-upd-2']);
        $p3 = Product::factory()->create(['external_id' => 'prod-upd-3']);

        $this->create->handle([
            'event' => 'promotion.created',
            'uuid' => 'promo-upd',
            'type' => 'new',
            'items' => [['uuid' => 'prod-upd-1'], ['uuid' => 'prod-upd-2']],
        ]);

        $this->assertTrue((bool) $p1->fresh()->is_new);
        $this->assertTrue((bool) $p2->fresh()->is_new);

        $this->update->handle([
            'event' => 'promotion.updated',
            'uuid' => 'promo-upd',
            'type' => 'new',
            'items' => [['uuid' => 'prod-upd-2'], ['uuid' => 'prod-upd-3']],
        ]);

        $this->assertFalse((bool) $p1->fresh()->is_new, 'Удалённый из items товар должен потерять флаг');
        $this->assertTrue((bool) $p2->fresh()->is_new, 'Оставшийся товар сохраняет флаг');
        $this->assertTrue((bool) $p3->fresh()->is_new, 'Добавленный товар получает флаг');

        $promotion = ErpPromotion::where('uuid', 'promo-upd')->first();
        $this->assertEquals(
            ['prod-upd-2', 'prod-upd-3'],
            $promotion->products->pluck('external_id')->sort()->values()->all(),
        );
    }

    #[Test]
    public function changing_type_recalculates_both_flags(): void
    {
        $product = Product::factory()->create([
            'external_id' => 'prod-type-change',
            'is_new' => false,
            'is_bestseller' => false,
        ]);

        $this->create->handle([
            'event' => 'promotion.created',
            'uuid' => 'promo-typeswitch',
            'type' => 'new',
            'items' => [['uuid' => 'prod-type-change']],
        ]);

        $this->assertTrue((bool) $product->fresh()->is_new);
        $this->assertFalse((bool) $product->fresh()->is_bestseller);

        $this->update->handle([
            'event' => 'promotion.updated',
            'uuid' => 'promo-typeswitch',
            'type' => 'bestseller',
            'items' => [['uuid' => 'prod-type-change']],
        ]);

        $this->assertFalse((bool) $product->fresh()->is_new, 'Старый тип больше не помечает товар');
        $this->assertTrue((bool) $product->fresh()->is_bestseller, 'Новый тип выставлен');
    }

    #[Test]
    public function upserts_promotion_if_not_exists(): void
    {
        $product = Product::factory()->create(['external_id' => 'prod-upsert']);

        $this->update->handle([
            'event' => 'promotion.updated',
            'uuid' => 'promo-upsert',
            'type' => 'liquidation',
            'items' => [['uuid' => 'prod-upsert']],
        ]);

        $this->assertDatabaseHas('erp_promotions', [
            'uuid' => 'promo-upsert',
            'type' => 'liquidation',
        ]);
        $this->assertTrue((bool) $product->fresh()->is_liquidation);
    }

    #[Test]
    public function does_not_clear_flag_if_product_is_in_another_promotion_of_same_type(): void
    {
        $product = Product::factory()->create(['external_id' => 'prod-multi', 'is_new' => false]);

        $this->create->handle([
            'event' => 'promotion.created',
            'uuid' => 'promo-a',
            'type' => 'new',
            'items' => [['uuid' => 'prod-multi']],
        ]);
        $this->create->handle([
            'event' => 'promotion.created',
            'uuid' => 'promo-b',
            'type' => 'new',
            'items' => [['uuid' => 'prod-multi']],
        ]);

        $this->assertTrue((bool) $product->fresh()->is_new);

        $this->update->handle([
            'event' => 'promotion.updated',
            'uuid' => 'promo-a',
            'type' => 'new',
            'items' => [],
        ]);

        $this->assertTrue(
            (bool) $product->fresh()->is_new,
            'Флаг сохраняется, пока товар привязан к любой другой промо-группе того же type',
        );
    }
}
