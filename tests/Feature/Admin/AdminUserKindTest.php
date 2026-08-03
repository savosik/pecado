<?php

namespace Tests\Feature\Admin;

use App\Enums\UserKind;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Тип аккаунта в админке: им размечают тех, кого 1С прислала партнёром,
 * а работает он в компании (закупщики, кладовщики) или не является человеком
 * вовсе (интеграционные учётки).
 */
class AdminUserKindTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $user, array $overrides = []): array
    {
        return array_merge([
            'name' => $user->name,
            'email' => $user->email,
            'roles' => [],
        ], $overrides);
    }

    #[Test]
    public function admin_can_mark_user_as_service_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $user->id), $this->payload($user, [
                'user_kind' => UserKind::SERVICE->value,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(UserKind::SERVICE, $user->refresh()->user_kind);
    }

    #[Test]
    public function assigning_a_role_marks_account_as_staff(): void
    {
        // Ровно тот сценарий, из-за которого баг вернулся бы сам собой:
        // завели закупщика, а он снова оказался в CRM среди клиентов.
        $user = User::factory()->create();

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $user->id), $this->payload($user, [
                'roles' => ['catalogist'],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(UserKind::STAFF, $user->refresh()->user_kind);
    }

    #[Test]
    public function explicit_kind_wins_over_role_autodetection(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $user->id), $this->payload($user, [
                'roles' => ['catalogist'],
                'user_kind' => UserKind::CLIENT->value,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(UserKind::CLIENT, $user->refresh()->user_kind);
    }

    #[Test]
    public function user_list_can_be_filtered_by_kind(): void
    {
        User::factory()->count(2)->create();
        $service = User::factory()->service()->create();

        $response = $this->actingAs($this->admin)
            ->get(route('admin.users.index', ['user_kind' => UserKind::SERVICE->value]))
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->where('users.total', 1)
            ->where('users.data.0.id', $service->id)
        );
    }

    #[Test]
    public function unknown_kind_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $user->id), $this->payload($user, [
                'user_kind' => 'partner',
            ]))
            ->assertSessionHasErrors('user_kind');
    }
}
