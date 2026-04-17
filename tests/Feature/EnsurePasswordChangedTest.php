<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EnsurePasswordChangedTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────
    // Shared Inertia data содержит must_change_password
    // ──────────────────────────────────────────────

    #[Test]
    public function it_shares_must_change_password_flag_via_inertia(): void
    {
        $user = User::factory()->create([
            'must_change_password' => true,
            'status' => UserStatus::ACTIVE,
        ]);

        $response = $this->actingAs($user)->get('/cabinet/dashboard');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->where('auth.user.must_change_password', true)
        );
    }

    #[Test]
    public function it_shares_must_change_password_false_when_not_required(): void
    {
        $user = User::factory()->create([
            'must_change_password' => false,
            'status' => UserStatus::ACTIVE,
        ]);

        $response = $this->actingAs($user)->get('/cabinet/dashboard');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->where('auth.user.must_change_password', false)
        );
    }

    // ──────────────────────────────────────────────
    // Cabinet доступен и при must_change_password = true
    // (нет редиректа — диалог на фронтенде)
    // ──────────────────────────────────────────────

    #[Test]
    public function it_allows_cabinet_access_when_must_change_password_is_true(): void
    {
        $user = User::factory()->create([
            'must_change_password' => true,
            'status' => UserStatus::ACTIVE,
        ]);

        $cabinetRoutes = [
            '/cabinet/dashboard',
            '/cabinet/profile',
            '/cabinet/orders',
        ];

        foreach ($cabinetRoutes as $route) {
            $response = $this->actingAs($user)->get($route);
            $response->assertStatus(200, "Route {$route} should return 200");
        }
    }

    // ──────────────────────────────────────────────
    // Сброс must_change_password после смены пароля
    // ──────────────────────────────────────────────

    #[Test]
    public function it_resets_must_change_password_after_password_update(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
            'must_change_password' => true,
            'status' => UserStatus::ACTIVE,
        ]);

        $response = $this->actingAs($user)->put('/cabinet/change-password', [
            'current_password' => 'old-password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]);

        $response->assertRedirect();

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('new-secure-password', $user->password));
    }

    #[Test]
    public function it_rejects_wrong_current_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
            'must_change_password' => true,
            'status' => UserStatus::ACTIVE,
        ]);

        $response = $this->actingAs($user)->put('/cabinet/change-password', [
            'current_password' => 'wrong-password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]);

        $response->assertSessionHasErrors('current_password');

        $user->refresh();
        $this->assertTrue($user->must_change_password);
    }
}
