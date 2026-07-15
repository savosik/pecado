<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CrmAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    #[Test]
    public function guest_is_redirected_to_login(): void
    {
        $this->get('/crm')->assertRedirect('/login');
    }

    #[Test]
    public function client_without_roles_cannot_enter_crm(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/crm')
            ->assertRedirect('/');
    }

    #[Test]
    public function sales_head_can_enter_crm(): void
    {
        $this->actingAs($this->userWithRole('sales-head'))
            ->get('/crm')
            ->assertOk();
    }

    #[Test]
    public function sales_manager_can_enter_crm(): void
    {
        $this->actingAs($this->userWithRole('sales-manager'))
            ->get('/crm')
            ->assertOk();
    }

    #[Test]
    public function super_admin_can_enter_crm(): void
    {
        $this->actingAs($this->userWithRole('super-admin'))
            ->get('/crm')
            ->assertOk();
    }

    #[Test]
    public function content_manager_without_crm_permissions_cannot_enter_crm(): void
    {
        $this->actingAs($this->userWithRole('content-manager'))
            ->get('/crm')
            ->assertRedirect('/');
    }

    #[Test]
    public function crm_pages_render_through_the_crm_root_view(): void
    {
        // Ловит опечатку в HandleCrmInertiaRequests::$rootView: иначе CRM
        // отрендерилась бы в admin.blade.php, и это осталось бы незамеченным.
        $this->actingAs($this->userWithRole('sales-head'))
            ->get('/crm')
            ->assertOk()
            ->assertSee('- CRM', escape: false)
            ->assertDontSee('Админ-панель');
    }

    #[Test]
    public function sales_head_can_view_team(): void
    {
        $this->actingAs($this->userWithRole('sales-head'))
            ->get('/crm/team')
            ->assertOk();
    }

    #[Test]
    public function sales_manager_cannot_view_team(): void
    {
        $this->actingAs($this->userWithRole('sales-manager'))
            ->get('/crm/team')
            ->assertForbidden();
    }
}
