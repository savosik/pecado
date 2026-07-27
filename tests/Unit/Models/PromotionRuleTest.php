<?php

namespace Tests\Unit\Models;

use App\Enums\PromotionRuleMode;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Модель правила акции: скоупы периода, режима и связи.
 */
class PromotionRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_active_returns_only_enabled_rules(): void
    {
        $enabled = PromotionRule::factory()->active()->create();
        PromotionRule::factory()->create(['is_active' => false]);

        $ids = PromotionRule::active()->pluck('id')->all();

        $this->assertSame([$enabled->id], $ids);
    }

    public function test_scope_active_respects_period_bounds(): void
    {
        Carbon::setTestNow('2026-07-27 12:00:00');

        $inside = PromotionRule::factory()->active()->create([
            'starts_at' => '2026-07-01 00:00:00',
            'ends_at' => '2026-07-31 23:59:59',
        ]);
        $unbounded = PromotionRule::factory()->active()->create([
            'starts_at' => null,
            'ends_at' => null,
        ]);
        PromotionRule::factory()->active()->create([
            'starts_at' => '2026-08-01 00:00:00',
            'ends_at' => null,
        ]);
        PromotionRule::factory()->active()->create([
            'starts_at' => null,
            'ends_at' => '2026-07-26 23:59:59',
        ]);

        $ids = PromotionRule::active()->pluck('id')->sort()->values()->all();

        $this->assertSame([$inside->id, $unbounded->id], $ids);

        Carbon::setTestNow();
    }

    public function test_rule_starting_today_at_23_59_is_not_active_yet(): void
    {
        Carbon::setTestNow('2026-07-27 12:00:00');

        $rule = PromotionRule::factory()->active()->create([
            'starts_at' => '2026-07-27 23:59:00',
            'ends_at' => null,
        ]);

        $this->assertFalse(PromotionRule::active()->whereKey($rule->id)->exists());
        $this->assertFalse($rule->isActiveAt());

        Carbon::setTestNow('2026-07-27 23:59:00');

        $this->assertTrue(PromotionRule::active()->whereKey($rule->id)->exists());
        $this->assertTrue($rule->isActiveAt());

        Carbon::setTestNow();
    }

    public function test_period_bounds_are_inclusive(): void
    {
        Carbon::setTestNow('2026-07-31 23:59:59');

        $rule = PromotionRule::factory()->active()->create([
            'starts_at' => '2026-07-01 00:00:00',
            'ends_at' => '2026-07-31 23:59:59',
        ]);

        $this->assertTrue(PromotionRule::active()->whereKey($rule->id)->exists());

        Carbon::setTestNow('2026-08-01 00:00:00');

        $this->assertFalse(PromotionRule::active()->whereKey($rule->id)->exists());

        Carbon::setTestNow();
    }

    public function test_scope_active_accepts_explicit_moment(): void
    {
        $rule = PromotionRule::factory()->active()->create([
            'starts_at' => '2026-07-01 00:00:00',
            'ends_at' => '2026-07-31 23:59:59',
        ]);

        $this->assertTrue(
            PromotionRule::active(Carbon::parse('2026-07-15 10:00:00'))->whereKey($rule->id)->exists()
        );
        $this->assertFalse(
            PromotionRule::active(Carbon::parse('2026-09-15 10:00:00'))->whereKey($rule->id)->exists()
        );
    }

    public function test_scope_for_mode_filters_by_mode(): void
    {
        $info = PromotionRule::factory()->create();
        $issuing = PromotionRule::factory()->issuing()->create();

        $this->assertSame([$info->id], PromotionRule::forMode(PromotionRuleMode::INFO)->pluck('id')->all());
        $this->assertSame([$issuing->id], PromotionRule::forMode('issue')->pluck('id')->all());
    }

    public function test_mode_defaults_to_info(): void
    {
        $rule = PromotionRule::create([
            'name' => 'Без указания режима',
            'conditions' => ['mode' => 'all', 'items' => []],
            'rewards' => [],
        ]);

        $this->assertSame(PromotionRuleMode::INFO, $rule->fresh()->mode);
        $this->assertFalse($rule->fresh()->is_active);
    }

    public function test_applies_to_channel(): void
    {
        $everywhere = PromotionRule::factory()->create(['audience' => null]);
        $emptyList = PromotionRule::factory()->create(['audience' => ['channels' => []]]);
        $siteOnly = PromotionRule::factory()->create(['audience' => ['channels' => ['site']]]);

        $this->assertTrue($everywhere->appliesToChannel(PromotionRule::CHANNEL_SITE));
        $this->assertTrue($everywhere->appliesToChannel(PromotionRule::CHANNEL_API));
        $this->assertTrue($emptyList->appliesToChannel(PromotionRule::CHANNEL_API));
        $this->assertTrue($siteOnly->appliesToChannel(PromotionRule::CHANNEL_SITE));
        $this->assertFalse($siteOnly->appliesToChannel(PromotionRule::CHANNEL_API));
    }

    public function test_promotion_relation_is_optional(): void
    {
        $promotion = Promotion::factory()->create();

        $attached = PromotionRule::factory()->create(['promotion_id' => $promotion->id]);
        $standalone = PromotionRule::factory()->create();

        $this->assertTrue($attached->promotion->is($promotion));
        $this->assertNull($standalone->promotion);
        $this->assertTrue($promotion->rules->contains($attached));
    }

    public function test_products_relation_splits_by_role(): void
    {
        $rule = PromotionRule::factory()->create();
        $conditionProduct = Product::factory()->create();
        $rewardProduct = Product::factory()->create();

        // Обсервер уже материализовал участников фабричного правила — начинаем с чистого списка
        $rule->products()->detach();

        $rule->products()->attach($conditionProduct->id, ['role' => PromotionRule::ROLE_CONDITION]);
        $rule->products()->attach($rewardProduct->id, ['role' => PromotionRule::ROLE_REWARD]);

        $this->assertSame([$conditionProduct->id], $rule->conditionProducts()->pluck('products.id')->all());
        $this->assertSame([$rewardProduct->id], $rule->rewardProducts()->pluck('products.id')->all());
        $this->assertCount(2, $rule->products()->get());
    }

    public function test_json_fields_are_cast_to_arrays(): void
    {
        $rule = PromotionRule::factory()->quantityThreshold(5)->create([
            'audience' => ['channels' => ['site']],
            'limits' => ['per_client_total' => 1, 'total' => null],
        ]);

        $fresh = $rule->fresh();

        $this->assertSame('quantity', $fresh->conditions['items'][0]['aggregate']);
        $this->assertCount(1, $fresh->rewards);
        $this->assertSame(['channels' => ['site']], $fresh->audience);
        $this->assertSame(1, $fresh->limits['per_client_total']);
    }

    public function test_soft_deleted_rule_stays_in_database(): void
    {
        $rule = PromotionRule::factory()->create();

        $rule->delete();

        $this->assertSoftDeleted('promotion_rules', ['id' => $rule->id]);
        $this->assertNotNull(PromotionRule::withTrashed()->find($rule->id));
    }
}
