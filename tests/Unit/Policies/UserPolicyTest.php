<?php

namespace Tests\Unit\Policies;

use App\Models\User;
use App\Policies\UserPolicy;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class UserPolicyTest extends TestCase
{
    private UserPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new UserPolicy();
    }

    #[Test]
    public function admin_can_view_any_users(): void
    {
        $admin = $this->createMock(User::class);
        $admin->method('__get')->willReturnCallback(fn($key) => $key === 'is_admin' ? true : null);

        $this->assertTrue($this->policy->viewAny($admin));
    }

    #[Test]
    public function regular_user_cannot_view_any_users(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => $key === 'is_admin' ? false : null);

        $this->assertFalse($this->policy->viewAny($user));
    }

    #[Test]
    public function admin_can_view_any_user(): void
    {
        $admin = $this->createMock(User::class);
        $admin->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => true,
            'id' => 1,
            default => null
        });

        $targetUser = $this->createMock(User::class);
        $targetUser->method('__get')->willReturnCallback(fn($key) => $key === 'id' ? 2 : null);

        $this->assertTrue($this->policy->view($admin, $targetUser));
    }

    #[Test]
    public function user_can_view_own_profile(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => false,
            'id' => 1,
            default => null
        });

        $this->assertTrue($this->policy->view($user, $user));
    }

    #[Test]
    public function user_cannot_view_other_user(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => false,
            'id' => 1,
            default => null
        });

        $otherUser = $this->createMock(User::class);
        $otherUser->method('__get')->willReturnCallback(fn($key) => $key === 'id' ? 2 : null);

        $this->assertFalse($this->policy->view($user, $otherUser));
    }

    #[Test]
    public function admin_can_update_any_user(): void
    {
        $admin = $this->createMock(User::class);
        $admin->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => true,
            'id' => 1,
            default => null
        });

        $targetUser = $this->createMock(User::class);
        $targetUser->method('__get')->willReturnCallback(fn($key) => $key === 'id' ? 2 : null);

        $this->assertTrue($this->policy->update($admin, $targetUser));
    }

    #[Test]
    public function user_can_update_own_profile(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => false,
            'id' => 1,
            default => null
        });

        $this->assertTrue($this->policy->update($user, $user));
    }

    #[Test]
    public function user_cannot_update_other_user(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => false,
            'id' => 1,
            default => null
        });

        $otherUser = $this->createMock(User::class);
        $otherUser->method('__get')->willReturnCallback(fn($key) => $key === 'id' ? 2 : null);

        $this->assertFalse($this->policy->update($user, $otherUser));
    }

    #[Test]
    public function admin_can_delete_user(): void
    {
        $admin = $this->createMock(User::class);
        $admin->method('__get')->willReturnCallback(fn($key) => $key === 'is_admin' ? true : null);

        $targetUser = $this->createMock(User::class);

        $this->assertTrue($this->policy->delete($admin, $targetUser));
    }

    #[Test]
    public function regular_user_cannot_delete_user(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => $key === 'is_admin' ? false : null);

        $targetUser = $this->createMock(User::class);

        $this->assertFalse($this->policy->delete($user, $targetUser));
    }
}
