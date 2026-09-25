<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    // ─── Page Rendering ───────────────────────

    public function test_login_page_renders(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Auth/Login'));
    }

    public function test_register_page_renders(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Auth/Register'));
    }

    public function test_forgot_password_page_renders(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Auth/ForgotPassword'));
    }

    // ─── Registration ─────────────────────────

    public function test_user_can_register(): void
    {
        $response = $this->post('/register', [
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => true,
        ]);

        $response->assertRedirect('/onboarding');
        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
        ]);
        $this->assertAuthenticated();
    }

    public function test_user_cannot_register_without_terms_accepted(): void
    {
        $response = $this->post('/register', [
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertSessionHasErrors('terms_accepted');
        $this->assertDatabaseMissing('users', [
            'email' => 'test@example.com',
        ]);
        $this->assertGuest();
    }

    // ─── Login ────────────────────────────────

    public function test_user_can_login(): void
    {
        $user = User::factory()->create([
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_admin_login_redirects_to_admin(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_from_popup_returns_to_the_page_where_it_happened(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'return_to' => '/news/kak-vybrat-tovar?utm=x#top',
        ]);

        $response->assertRedirect('/news/kak-vybrat-tovar?utm=x#top');
        $this->assertAuthenticatedAs($user);
    }

    public function test_popup_return_path_beats_stale_intended_url(): void
    {
        $user = User::factory()->create();

        $response = $this->withSession(['url.intended' => 'http://localhost/profile'])
            ->post('/login', [
                'email' => $user->email,
                'password' => 'password',
                'return_to' => '/catalog/whips',
            ]);

        $response->assertRedirect('/catalog/whips');
        $response->assertSessionMissing('url.intended');
    }

    public function test_staff_login_from_popup_also_stays_on_the_page(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'return_to' => '/catalog/whips',
        ]);

        $response->assertRedirect('/catalog/whips');
    }

    #[DataProvider('unsafeReturnPaths')]
    public function test_unsafe_return_path_falls_back_to_default(string $returnTo): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'return_to' => $returnTo,
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public static function unsafeReturnPaths(): array
    {
        return [
            'чужой хост' => ['https://evil.example/phish'],
            'protocol-relative' => ['//evil.example/phish'],
            'обратный слеш' => ['/\\evil.example'],
            'страница входа' => ['/login'],
            'регистрация' => ['/register?ref=1'],
            'api' => ['/api/content/products'],
            'пустая строка' => [''],
            'без ведущего слеша' => ['news/x'],
        ];
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    // ─── Logout ───────────────────────────────

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect('/login');
        $this->assertGuest();
    }

    // ─── Admin Access Protection ──────────────

    public function test_guest_cannot_access_admin(): void
    {
        $response = $this->get('/admin');

        $response->assertRedirect('/login');
    }

    public function test_non_admin_cannot_access_admin(): void
    {
        $user = User::factory()->create([
        ]);

        $response = $this->actingAs($user)->get('/admin');

        $response->assertRedirect('/');
    }

    public function test_admin_can_access_admin(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super-admin');

        $response = $this->actingAs($user)->get('/admin');

        $response->assertStatus(200);
    }

    // ─── Password Reset ───────────────────────

    public function test_user_can_request_password_reset(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/forgot-password', [
            'email' => $user->email,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }

    public function test_reset_password_page_renders(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $response = $this->get("/reset-password/{$token}?email={$user->email}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Auth/ResetPassword')
            ->has('token')
            ->has('email')
        );
    }

    public function test_user_can_reset_password(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $response = $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHas('success');
    }

    // ─── Guest Middleware ─────────────────────

    public function test_authenticated_user_cannot_access_login(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/login');

        $response->assertRedirect('/');
    }

    public function test_authenticated_user_cannot_access_register(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/register');

        $response->assertRedirect('/');
    }
}
