<?php

namespace Tests\Unit\Policies;

use App\Models\DeliveryAddress;
use App\Models\User;
use App\Policies\DeliveryAddressPolicy;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class DeliveryAddressPolicyTest extends TestCase
{
    private DeliveryAddressPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new DeliveryAddressPolicy();
    }

    #[Test]
    public function any_user_can_view_any_delivery_addresses(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => $key === 'is_admin' ? false : null);

        $this->assertTrue($this->policy->viewAny($user));
    }

    #[Test]
    public function admin_can_view_any_delivery_address(): void
    {
        $admin = $this->createMock(User::class);
        $admin->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => true,
            'id' => 1,
            default => null
        });

        $address = $this->createMock(DeliveryAddress::class);
        $address->method('__get')->willReturnCallback(fn($key) => $key === 'user_id' ? 2 : null);

        $this->assertTrue($this->policy->view($admin, $address));
    }

    #[Test]
    public function user_can_view_own_delivery_address(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => false,
            'id' => 1,
            default => null
        });

        $address = $this->createMock(DeliveryAddress::class);
        $address->method('__get')->willReturnCallback(fn($key) => $key === 'user_id' ? 1 : null);

        $this->assertTrue($this->policy->view($user, $address));
    }

    #[Test]
    public function user_cannot_view_other_user_delivery_address(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => false,
            'id' => 1,
            default => null
        });

        $address = $this->createMock(DeliveryAddress::class);
        $address->method('__get')->willReturnCallback(fn($key) => $key === 'user_id' ? 2 : null);

        $this->assertFalse($this->policy->view($user, $address));
    }

    #[Test]
    public function any_user_can_create_delivery_address(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => $key === 'is_admin' ? false : null);

        $this->assertTrue($this->policy->create($user));
    }

    #[Test]
    public function admin_can_update_any_delivery_address(): void
    {
        $admin = $this->createMock(User::class);
        $admin->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => true,
            'id' => 1,
            default => null
        });

        $address = $this->createMock(DeliveryAddress::class);
        $address->method('__get')->willReturnCallback(fn($key) => $key === 'user_id' ? 2 : null);

        $this->assertTrue($this->policy->update($admin, $address));
    }

    #[Test]
    public function user_can_update_own_delivery_address(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => false,
            'id' => 1,
            default => null
        });

        $address = $this->createMock(DeliveryAddress::class);
        $address->method('__get')->willReturnCallback(fn($key) => $key === 'user_id' ? 1 : null);

        $this->assertTrue($this->policy->update($user, $address));
    }

    #[Test]
    public function user_cannot_update_other_user_delivery_address(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => false,
            'id' => 1,
            default => null
        });

        $address = $this->createMock(DeliveryAddress::class);
        $address->method('__get')->willReturnCallback(fn($key) => $key === 'user_id' ? 2 : null);

        $this->assertFalse($this->policy->update($user, $address));
    }

    #[Test]
    public function admin_can_delete_any_delivery_address(): void
    {
        $admin = $this->createMock(User::class);
        $admin->method('__get')->willReturnCallback(fn($key) => $key === 'is_admin' ? true : null);

        $address = $this->createMock(DeliveryAddress::class);

        $this->assertTrue($this->policy->delete($admin, $address));
    }

    #[Test]
    public function user_can_delete_own_delivery_address(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => false,
            'id' => 1,
            default => null
        });

        $address = $this->createMock(DeliveryAddress::class);
        $address->method('__get')->willReturnCallback(fn($key) => $key === 'user_id' ? 1 : null);

        $this->assertTrue($this->policy->delete($user, $address));
    }

    #[Test]
    public function user_cannot_delete_other_user_delivery_address(): void
    {
        $user = $this->createMock(User::class);
        $user->method('__get')->willReturnCallback(fn($key) => match($key) {
            'is_admin' => false,
            'id' => 1,
            default => null
        });

        $address = $this->createMock(DeliveryAddress::class);
        $address->method('__get')->willReturnCallback(fn($key) => $key === 'user_id' ? 2 : null);

        $this->assertFalse($this->policy->delete($user, $address));
    }
}
