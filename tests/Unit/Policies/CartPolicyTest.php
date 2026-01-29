<?php

namespace Tests\Unit\Policies;

use App\Models\Cart;
use App\Models\User;
use App\Policies\CartPolicy;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CartPolicyTest extends TestCase
{
    private CartPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new CartPolicy();
    }

    #[Test]
    public function any_user_can_view_any_carts(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => $key === 'is_admin' ? false : null);

        $this->assertTrue($this->policy->viewAny($user));
    }

    #[Test]
    public function admin_can_view_any_cart(): void
    {
        $admin = $this->createMock(User::class);
        $admin->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => true,
            'id' => 1,
            default => null
        });

        $cart = $this->createMock(Cart::class);
        $cart->method('__get')->willReturnCallback(fn($key) => $key === 'user_id' ? 2 : null);

        $this->assertTrue($this->policy->view($admin, $cart));
    }

    #[Test]
    public function user_can_view_own_cart(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => false,
            'id' => 1,
            default => null
        });

        $cart = $this->createMock(Cart::class);
        $cart->method('__get')->willReturnCallback(fn($key) => $key === 'user_id' ? 1 : null);

        $this->assertTrue($this->policy->view($user, $cart));
    }

    #[Test]
    public function user_cannot_view_other_user_cart(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => false,
            'id' => 1,
            default => null
        });

        $cart = $this->createMock(Cart::class);
        $cart->method('__get')->willReturnCallback(fn($key) => $key === 'user_id' ? 2 : null);

        $this->assertFalse($this->policy->view($user, $cart));
    }

    #[Test]
    public function any_user_can_create_cart(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => $key === 'is_admin' ? false : null);

        $this->assertTrue($this->policy->create($user));
    }

    #[Test]
    public function admin_can_update_any_cart(): void
    {
        $admin = $this->createMock(User::class);
        $admin->method('__get')->willReturnCallback(fn($key) => $key === 'is_admin' ? true : null);

        $cart = $this->createMock(Cart::class);

        $this->assertTrue($this->policy->update($admin, $cart));
    }

    #[Test]
    public function user_can_update_own_cart(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => false,
            'id' => 1,
            default => null
        });

        $cart = $this->createMock(Cart::class);
        $cart->method('__get')->willReturnCallback(fn($key) => $key === 'user_id' ? 1 : null);

        $this->assertTrue($this->policy->update($user, $cart));
    }

    #[Test]
    public function user_cannot_update_other_user_cart(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => false,
            'id' => 1,
            default => null
        });

        $cart = $this->createMock(Cart::class);
        $cart->method('__get')->willReturnCallback(fn($key) => $key === 'user_id' ? 2 : null);

        $this->assertFalse($this->policy->update($user, $cart));
    }

    #[Test]
    public function admin_can_delete_any_cart(): void
    {
        $admin = $this->createMock(User::class);
        $admin->method('__get')->willReturnCallback(fn($key) => $key === 'is_admin' ? true : null);

        $cart = $this->createMock(Cart::class);

        $this->assertTrue($this->policy->delete($admin, $cart));
    }

    #[Test]
    public function user_can_delete_own_cart(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => false,
            'id' => 1,
            default => null
        });

        $cart = $this->createMock(Cart::class);
        $cart->method('__get')->willReturnCallback(fn($key) => $key === 'user_id' ? 1 : null);

        $this->assertTrue($this->policy->delete($user, $cart));
    }

    #[Test]
    public function user_cannot_delete_other_user_cart(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => false,
            'id' => 1,
            default => null
        });

        $cart = $this->createMock(Cart::class);
        $cart->method('__get')->willReturnCallback(fn($key) => $key === 'user_id' ? 2 : null);

        $this->assertFalse($this->policy->delete($user, $cart));
    }

    #[Test]
    public function user_can_add_item_to_own_cart(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => false,
            'id' => 1,
            default => null
        });

        $cart = $this->createMock(Cart::class);
        $cart->method('__get')->willReturnCallback(fn($key) => $key === 'user_id' ? 1 : null);

        $this->assertTrue($this->policy->addItem($user, $cart));
    }

    #[Test]
    public function user_cannot_add_item_to_other_user_cart(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => false,
            'id' => 1,
            default => null
        });

        $cart = $this->createMock(Cart::class);
        $cart->method('__get')->willReturnCallback(fn($key) => $key === 'user_id' ? 2 : null);

        $this->assertFalse($this->policy->addItem($user, $cart));
    }

    #[Test]
    public function user_can_remove_item_from_own_cart(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => false,
            'id' => 1,
            default => null
        });

        $cart = $this->createMock(Cart::class);
        $cart->method('__get')->willReturnCallback(fn($key) => $key === 'user_id' ? 1 : null);

        $this->assertTrue($this->policy->removeItem($user, $cart));
    }

    #[Test]
    public function user_cannot_remove_item_from_other_user_cart(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => false,
            'id' => 1,
            default => null
        });

        $cart = $this->createMock(Cart::class);
        $cart->method('__get')->willReturnCallback(fn($key) => $key === 'user_id' ? 2 : null);

        $this->assertFalse($this->policy->removeItem($user, $cart));
    }

    #[Test]
    public function user_can_update_item_in_own_cart(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => false,
            'id' => 1,
            default => null
        });

        $cart = $this->createMock(Cart::class);
        $cart->method('__get')->willReturnCallback(fn($key) => $key === 'user_id' ? 1 : null);

        $this->assertTrue($this->policy->updateItem($user, $cart));
    }

    #[Test]
    public function user_cannot_update_item_in_other_user_cart(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => false,
            'id' => 1,
            default => null
        });

        $cart = $this->createMock(Cart::class);
        $cart->method('__get')->willReturnCallback(fn($key) => $key === 'user_id' ? 2 : null);

        $this->assertFalse($this->policy->updateItem($user, $cart));
    }
}
