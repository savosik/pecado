<?php

namespace Tests\Feature\Order;

use App\Models\OrderReserveOverride;
use App\Models\User;
use App\Services\Order\ReservePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * v16.9.0, режим «Заказы в резерве»: канареечное сужение охвата на время
 * совместных испытаний Р-1…Р-6 на боевом контуре.
 *
 * Пустой список — штатное состояние (режим для всех участников 1С).
 * Непустой — только перечисленные, чтобы прогон не открыл удержание остатков
 * всем 84 интернет-магазинам разом.
 */
class ReserveCanaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['order_reserve.enabled' => true]);
    }

    #[Test]
    public function empty_canary_list_keeps_mode_open_for_all_participants(): void
    {
        config(['order_reserve.canary' => '']);
        $policy = app(ReservePolicy::class);

        $participant = User::factory()->create(['reserve_allowed' => true, 'erp_id' => 'uuid-a']);

        $this->assertSame([], $policy->canaryUuids());
        $this->assertTrue($policy->availableFor($participant));
    }

    #[Test]
    public function canary_list_narrows_mode_to_listed_partners_only(): void
    {
        $canary = User::factory()->create(['reserve_allowed' => true, 'erp_id' => 'uuid-canary']);
        $other = User::factory()->create(['reserve_allowed' => true, 'erp_id' => 'uuid-other']);

        config(['order_reserve.canary' => 'uuid-canary']);
        $policy = app(ReservePolicy::class);

        $this->assertTrue($policy->availableFor($canary));
        $this->assertFalse($policy->availableFor($other), 'участник вне списка режима не видит');
    }

    #[Test]
    public function canary_list_parses_spaces_and_multiple_uuids(): void
    {
        config(['order_reserve.canary' => ' uuid-one , uuid-two ,, ']);
        $policy = app(ReservePolicy::class);

        $this->assertSame(['uuid-one', 'uuid-two'], $policy->canaryUuids());

        $first = User::factory()->create(['reserve_allowed' => true, 'erp_id' => 'uuid-one']);
        $second = User::factory()->create(['reserve_allowed' => true, 'erp_id' => 'uuid-two']);

        $this->assertTrue($policy->availableFor($first));
        $this->assertTrue($policy->availableFor($second));
    }

    #[Test]
    public function canary_cannot_widen_scope_beyond_erp_flag(): void
    {
        // Канарейка без флага 1С резерв не получает: сайт сужает, но не расширяет
        config(['order_reserve.canary' => 'uuid-outsider']);
        $outsider = User::factory()->create(['reserve_allowed' => false, 'erp_id' => 'uuid-outsider']);

        $this->assertFalse(app(ReservePolicy::class)->availableFor($outsider));
    }

    #[Test]
    public function site_override_still_wins_over_canary(): void
    {
        // РОП отключил партнёра точечно — канареечный список это не отменяет
        config(['order_reserve.canary' => 'uuid-canary']);
        $canary = User::factory()->create(['reserve_allowed' => true, 'erp_id' => 'uuid-canary']);
        OrderReserveOverride::create(['user_id' => $canary->id, 'disabled' => true]);

        $this->assertFalse(app(ReservePolicy::class)->availableFor($canary));
    }

    #[Test]
    public function global_switch_still_wins_over_canary(): void
    {
        config(['order_reserve.enabled' => false, 'order_reserve.canary' => 'uuid-canary']);
        $canary = User::factory()->create(['reserve_allowed' => true, 'erp_id' => 'uuid-canary']);

        $this->assertFalse(app(ReservePolicy::class)->availableFor($canary));
    }
}
