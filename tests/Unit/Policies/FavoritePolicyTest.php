<?php

namespace Tests\Unit\Policies;

use App\Models\Favorite;
use App\Models\User;
use App\Policies\FavoritePolicy;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class FavoritePolicyTest extends TestCase
{
    private FavoritePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new FavoritePolicy();
    }

    #[Test]
    public function any_user_can_view_any_favorites(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => $key === 'is_admin' ? false : null);

        $this->assertTrue($this->policy->viewAny($user));
    }

    #[Test]
    public function admin_can_view_any_favorite(): void
    {
        $admin = $this->createMock(User::class);
        $admin->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => true,
            'id' => 1,
            default => null
        });

        $favorite = $this->createMock(Favorite::class);
        $favorite->method('__get')->willReturnCallback(fn($key) => $key === 'user_id' ? 2 : null);

        $this->assertTrue($this->policy->view($admin, $favorite));
    }

    #[Test]
    public function user_can_view_own_favorite(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => false,
            'id' => 1,
            default => null
        });

        $favorite = $this->createMock(Favorite::class);
        $favorite->method('__get')->willReturnCallback(fn($key) => $key === 'user_id' ? 1 : null);

        $this->assertTrue($this->policy->view($user, $favorite));
    }

    #[Test]
    public function user_cannot_view_other_user_favorite(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => false,
            'id' => 1,
            default => null
        });

        $favorite = $this->createMock(Favorite::class);
        $favorite->method('__get')->willReturnCallback(fn($key) => $key === 'user_id' ? 2 : null);

        $this->assertFalse($this->policy->view($user, $favorite));
    }

    #[Test]
    public function any_user_can_create_favorite(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => $key === 'is_admin' ? false : null);

        $this->assertTrue($this->policy->create($user));
    }

    #[Test]
    public function admin_can_delete_any_favorite(): void
    {
        $admin = $this->createMock(User::class);
        $admin->method('__get')->willReturnCallback(fn($key) => $key === 'is_admin' ? true : null);

        $favorite = $this->createMock(Favorite::class);

        $this->assertTrue($this->policy->delete($admin, $favorite));
    }

    #[Test]
    public function user_can_delete_own_favorite(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => false,
            'id' => 1,
            default => null
        });

        $favorite = $this->createMock(Favorite::class);
        $favorite->method('__get')->willReturnCallback(fn($key) => $key === 'user_id' ? 1 : null);

        $this->assertTrue($this->policy->delete($user, $favorite));
    }

    #[Test]
    public function user_cannot_delete_other_user_favorite(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => false,
            'id' => 1,
            default => null
        });

        $favorite = $this->createMock(Favorite::class);
        $favorite->method('__get')->willReturnCallback(fn($key) => $key === 'user_id' ? 2 : null);

        $this->assertFalse($this->policy->delete($user, $favorite));
    }
}
