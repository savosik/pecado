<?php

namespace Tests\Unit\Policies;

use App\Models\WishlistItem;
use App\Models\User;
use App\Policies\WishlistItemPolicy;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class WishlistItemPolicyTest extends TestCase
{
    private WishlistItemPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new WishlistItemPolicy();
    }

    #[Test]
    public function any_user_can_view_any_wishlist_items(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => $key === 'is_admin' ? false : null);

        $this->assertTrue($this->policy->viewAny($user));
    }

    #[Test]
    public function admin_can_view_any_wishlist_item(): void
    {
        $admin = $this->createMock(User::class);
        $admin->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => true,
            'id' => 1,
            default => null
        });

        $wishlistItem = $this->createMock(WishlistItem::class);
        $wishlistItem->method('__get')->willReturnCallback(fn($key) => $key === 'user_id' ? 2 : null);

        $this->assertTrue($this->policy->view($admin, $wishlistItem));
    }

    #[Test]
    public function user_can_view_own_wishlist_item(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => false,
            'id' => 1,
            default => null
        });

        $wishlistItem = $this->createMock(WishlistItem::class);
        $wishlistItem->method('__get')->willReturnCallback(fn($key) => $key === 'user_id' ? 1 : null);

        $this->assertTrue($this->policy->view($user, $wishlistItem));
    }

    #[Test]
    public function user_cannot_view_other_user_wishlist_item(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => false,
            'id' => 1,
            default => null
        });

        $wishlistItem = $this->createMock(WishlistItem::class);
        $wishlistItem->method('__get')->willReturnCallback(fn($key) => $key === 'user_id' ? 2 : null);

        $this->assertFalse($this->policy->view($user, $wishlistItem));
    }

    #[Test]
    public function any_user_can_create_wishlist_item(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => $key === 'is_admin' ? false : null);

        $this->assertTrue($this->policy->create($user));
    }

    #[Test]
    public function admin_can_delete_any_wishlist_item(): void
    {
        $admin = $this->createMock(User::class);
        $admin->method('__get')->willReturnCallback(fn($key) => $key === 'is_admin' ? true : null);

        $wishlistItem = $this->createMock(WishlistItem::class);

        $this->assertTrue($this->policy->delete($admin, $wishlistItem));
    }

    #[Test]
    public function user_can_delete_own_wishlist_item(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => false,
            'id' => 1,
            default => null
        });

        $wishlistItem = $this->createMock(WishlistItem::class);
        $wishlistItem->method('__get')->willReturnCallback(fn($key) => $key === 'user_id' ? 1 : null);

        $this->assertTrue($this->policy->delete($user, $wishlistItem));
    }

    #[Test]
    public function user_cannot_delete_other_user_wishlist_item(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => false,
            'id' => 1,
            default => null
        });

        $wishlistItem = $this->createMock(WishlistItem::class);
        $wishlistItem->method('__get')->willReturnCallback(fn($key) => $key === 'user_id' ? 2 : null);

        $this->assertFalse($this->policy->delete($user, $wishlistItem));
    }
}
