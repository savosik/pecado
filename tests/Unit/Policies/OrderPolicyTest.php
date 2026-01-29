<?php

namespace Tests\Unit\Policies;

use App\Models\Order;
use App\Models\User;
use App\Policies\OrderPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderPolicyTest extends TestCase
{
    use RefreshDatabase;

    private OrderPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new OrderPolicy();
    }

    public function test_admin_can_view_any_order()
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $order = Order::factory()->create();

        $this->assertTrue($this->policy->view($admin, $order));
    }

    public function test_user_can_view_own_order()
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($this->policy->view($user, $order));
    }

    public function test_user_cannot_view_others_order()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $otherUser->id]);

        $this->assertFalse($this->policy->view($user, $order));
    }

    public function test_user_cannot_update_order()
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);

        $this->assertFalse($this->policy->update($user, $order));
    }

    public function test_admin_can_update_order()
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $order = Order::factory()->create();

        $this->assertTrue($this->policy->update($admin, $order));
    }
}
