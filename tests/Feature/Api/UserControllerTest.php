<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function admin_can_list_all_users(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        User::factory()->count(5)->create();

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/users');

        $response->assertOk()
            ->assertJsonStructure([
                'current_page',
                'data' => [['id', 'name', 'email']],
                'total',
            ]);
    }

    #[Test]
    public function regular_user_cannot_list_all_users(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/users');

        $response->assertForbidden();
    }

    #[Test]
    public function admin_can_view_any_user(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $targetUser = User::factory()->create();

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/users/{$targetUser->id}");

        $response->assertOk()
            ->assertJsonFragment(['id' => $targetUser->id]);
    }

    #[Test]
    public function user_can_view_own_profile(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/users/{$user->id}");

        $response->assertOk()
            ->assertJsonFragment(['id' => $user->id]);
    }

    #[Test]
    public function user_cannot_view_other_user_profile(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $otherUser = User::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/users/{$otherUser->id}");

        $response->assertForbidden();
    }

    #[Test]
    public function admin_can_update_any_user(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $targetUser = User::factory()->create(['surname' => 'OldSurname']);

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/users/{$targetUser->id}", [
            'surname' => 'NewSurname',
        ]);

        $response->assertOk()
            ->assertJsonFragment(['surname' => 'NewSurname']);

        $this->assertDatabaseHas('users', [
            'id' => $targetUser->id,
            'surname' => 'NewSurname',
        ]);
    }

    #[Test]
    public function user_can_update_own_profile(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'phone' => '111']);

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/users/{$user->id}", [
            'phone' => '999',
        ]);

        $response->assertOk()
            ->assertJsonFragment(['phone' => '999']);
    }

    #[Test]
    public function user_cannot_update_other_user_profile(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $otherUser = User::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/users/{$otherUser->id}", [
            'surname' => 'Hacked',
        ]);

        $response->assertForbidden();
    }

    #[Test]
    public function non_admin_cannot_set_is_admin_flag(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/users/{$user->id}", [
            'is_admin' => true,
        ]);

        $response->assertOk();

        $user->refresh();
        $this->assertFalse($user->is_admin);
    }

    #[Test]
    public function admin_can_set_is_admin_flag(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $targetUser = User::factory()->create(['is_admin' => false]);

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/users/{$targetUser->id}", [
            'is_admin' => true,
        ]);

        $response->assertOk();

        $targetUser->refresh();
        $this->assertTrue($targetUser->is_admin);
    }

    #[Test]
    public function unauthenticated_user_cannot_access_users(): void
    {
        $response = $this->getJson('/api/users');

        $response->assertUnauthorized();
    }
}
