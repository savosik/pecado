<?php

namespace Tests\Feature\Pickup;

use App\Models\GoodsIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** pick-04, pick-05: стадия сборки и обещание времени в кабинете клиента. */
class CabinetFulfilmentTest extends TestCase
{
    use PickupTestHelpers, RefreshDatabase;

    #[Test]
    public function orders_list_and_card_carry_fulfilment_stage(): void
    {
        config(['pickup.enabled' => true, 'pickup.handover_since' => null]);
        $client = $this->pickupClient();
        $ready = $this->pickupOrder($client);
        $this->goodsIssueFor($ready);
        $picking = $this->pickupOrder($client);
        $this->goodsIssueFor($picking, GoodsIssue::STATUS_TO_PICK);

        $this->actingAs($client)->get('/cabinet/orders')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('config.pickup_enabled', true)
                ->where('config.pickup_ready_count', 1)
                ->has('config.pickup_promise.text')
                ->where('orders.data', fn ($rows) => collect($rows)->pluck('fulfilment.stage', 'id')->all() == [$ready->id => 'ready', $picking->id => 'picking']));

        $this->actingAs($client)->get("/cabinet/orders/{$ready->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.fulfilment.stage', 'ready')
                ->where('order.fulfilment.label', 'Собран, ждёт выдачи')
                ->where('order.fulfilment.step', 3)
                ->where('order.fulfilment.packages_total', 2));
    }

    #[Test]
    public function switched_off_cabinet_looks_as_before(): void
    {
        config(['pickup.enabled' => false]);
        $client = $this->pickupClient();
        $order = $this->pickupOrder($client);
        $this->goodsIssueFor($order);

        $this->actingAs($client)->get("/cabinet/orders/{$order->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.fulfilment', null)
                ->where('config.pickup_enabled', false)
                ->where('config.pickup_promise', null));
    }
}
