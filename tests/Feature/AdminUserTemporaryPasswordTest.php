<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminUserTemporaryPasswordTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
    }

    #[Test]
    public function it_exposes_temporary_password_on_admin_edit_page_when_password_change_is_required(): void
    {
        $user = User::factory()->create([
            'email' => 'partner@example.com',
            'must_change_password' => true,
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.users.edit', $user));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Pages/Users/Edit', false)
            ->where('user.must_change_password', true)
            ->where('user.temporary_password', sprintf('%u', crc32($user->email)))
        );
    }

    #[Test]
    public function it_does_not_expose_temporary_password_on_admin_edit_page_when_password_change_is_not_required(): void
    {
        $user = User::factory()->create([
            'email' => 'regular@example.com',
            'must_change_password' => false,
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.users.edit', $user));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Pages/Users/Edit', false)
            ->where('user.must_change_password', false)
            ->where('user.temporary_password', null)
        );
    }
}
