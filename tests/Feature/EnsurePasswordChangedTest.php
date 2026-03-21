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
    // Middleware блокирует доступ
    // ──────────────────────────────────────────────

    #[Test]
    public function it_redirects_to_change_password_when_must_change_password_is_true(): void
    {
        $user = User::factory()->create([
            'must_change_password' => true,
            'status' => UserStatus::ACTIVE,
        ]);

        $response = $this->actingAs($user)->get('/cabinet/dashboard');

        $response->assertRedirect(route('cabinet.password.change'));
    }

    #[Test]
    public function it_allows_access_to_change_password_page_when_must_change(): void
    {
        $user = User::factory()->create([
            'must_change_password' => true,
            'status' => UserStatus::ACTIVE,
        ]);

        $response = $this->actingAs($user)->get('/cabinet/change-password');

        $response->assertStatus(200);
    }

    #[Test]
    public function it_allows_logout_when_must_change_password(): void
    {
        $user = User::factory()->create([
            'must_change_password' => true,
            'status' => UserStatus::ACTIVE,
        ]);

        $response = $this->actingAs($user)->post('/logout');

        // Logout redirects to login
        $response->assertRedirect('/login');
    }

    #[Test]
    public function it_allows_normal_access_when_must_change_password_is_false(): void
    {
        $user = User::factory()->create([
            'must_change_password' => false,
            'status' => UserStatus::ACTIVE,
        ]);

        $response = $this->actingAs($user)->get('/cabinet/dashboard');

        $response->assertStatus(200);
    }

    #[Test]
    public function it_returns_json_403_for_api_requests_when_must_change(): void
    {
        $user = User::factory()->create([
            'must_change_password' => true,
            'status' => UserStatus::ACTIVE,
        ]);

        $response = $this->actingAs($user)
            ->getJson('/cabinet/dashboard');

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'Необходимо сменить пароль.',
            ]);
    }

    // ──────────────────────────────────────────────
    // Сброс must_change_password после смены пароля
    // ──────────────────────────────────────────────

    #[Test]
    public function it_resets_must_change_password_after_password_update(): void
    {
        $user = User::factory()->create([
            'password'             => Hash::make('old-password'),
            'must_change_password' => true,
            'status'               => UserStatus::ACTIVE,
        ]);

        $response = $this->actingAs($user)->put('/cabinet/change-password', [
            'current_password'      => 'old-password',
            'password'              => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]);

        $response->assertRedirect();

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('new-secure-password', $user->password));
    }

    #[Test]
    public function it_redirects_to_other_cabinet_pages_until_password_changed(): void
    {
        $user = User::factory()->create([
            'must_change_password' => true,
            'status' => UserStatus::ACTIVE,
        ]);

        // Все эти маршруты должны редиректить на смену пароля
        $protectedRoutes = [
            '/cabinet/profile',
            '/cabinet/companies',
            '/cabinet/orders',
        ];

        foreach ($protectedRoutes as $route) {
            $response = $this->actingAs($user)->get($route);
            $response->assertRedirect(route('cabinet.password.change'), "Route {$route} should redirect");
        }
    }
}
