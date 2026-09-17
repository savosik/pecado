<?php

namespace Tests\Feature\Pickup;

use App\Enums\OrderFulfilmentStage as Stage;
use App\Models\GoodsIssue;
use App\Services\Pickup\OrderFulfilmentResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Имитатор склада для приёмочного тестирования на стенде: те же стадии, что и от шины; на проде отключён. */
class PickupDemoStepTest extends TestCase
{
    use PickupTestHelpers, RefreshDatabase;

    #[Test]
    public function demo_goods_issue_walks_order_through_client_stages(): void
    {
        config(['pickup.enabled' => true, 'pickup.handover_since' => null]);
        $order = $this->pickupOrder($this->pickupClient());
        $stage = fn () => app(OrderFulfilmentResolver::class)->forOrder($order->fresh())['stage'];

        $this->artisan('pickup:demo-step', ['order' => $order->id, 'status' => 'to_pick'])->assertSuccessful();
        $this->assertSame(Stage::PICKING->value, $stage());

        $this->artisan('pickup:demo-step', ['order' => $order->number])->assertSuccessful();
        $this->assertSame(Stage::READY->value, $stage());
        $this->assertSame(1, GoodsIssue::count(), 'повторный вызов ведёт тот же демо-ордер');

        $this->artisan('pickup:demo-step', ['order' => $order->id, 'status' => 'cancelled'])->assertSuccessful();
        $this->assertSame(Stage::SENT_TO_WAREHOUSE->value, $stage());
    }

    #[Test]
    public function command_refuses_to_run_in_production(): void
    {
        $order = $this->pickupOrder($this->pickupClient());
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('pickup:demo-step', ['order' => $order->id])->assertFailed();
        $this->assertSame(0, GoodsIssue::count());
    }
}
