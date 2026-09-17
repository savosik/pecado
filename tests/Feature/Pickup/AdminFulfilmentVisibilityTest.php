<?php

namespace Tests\Feature\Pickup;

use App\Models\User;
use App\Services\Pickup\HandoverService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** pick-15: менеджер видит в карточке заказа стадию сборки и кто, когда и кому выдал. */
class AdminFulfilmentVisibilityTest extends TestCase
{
    use PickupTestHelpers, RefreshDatabase;

    #[Test]
    public function order_card_shows_stage_and_handover_even_when_client_switch_is_off(): void
    {
        config(['pickup.enabled' => false, 'pickup.handover_since' => null]);

        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');

        $order = $this->pickupOrder($this->pickupClient());
        $issue = $this->goodsIssueFor($order);
        app(HandoverService::class)->issue($issue, User::factory()->create(['name' => 'Кладовщик Петров']), 'qr', null, ['recipient_name' => 'Олег']);

        $this->actingAs($admin)->get("/admin/orders/{$order->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.fulfilment.stage', 'handed_over')
                ->where('order.fulfilment.goods_issues.0.number', $issue->number)
                ->where('order.fulfilment.goods_issues.0.handed_by', 'Кладовщик Петров')
                ->where('order.fulfilment.goods_issues.0.recipient_name', 'Олег'));
    }
}
