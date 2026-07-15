<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Вход в /admin требует хотя бы одного НЕ-CRM права.
 * Страхует от регресса: раньше пускала любая роль.
 */
class AdminAccessHardeningTest extends TestCase
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
    public function crm_only_role_cannot_enter_admin(): void
    {
        // sales-head сейчас CRM-only. Если роли когда-нибудь выдадут админ-ресурсы,
        // этот кейс надо перевесить на явно созданную CRM-only роль.
        $this->actingAs($this->userWithRole('sales-head'))
            ->get('/admin')
            ->assertRedirect('/');
    }

    #[Test]
    public function role_without_any_permissions_cannot_enter_admin(): void
    {
        Role::create(['name' => 'empty-role', 'guard_name' => 'web']);

        $this->actingAs($this->userWithRole('empty-role'))
            ->get('/admin')
            ->assertRedirect('/');
    }

    #[Test]
    public function content_manager_still_enters_admin(): void
    {
        $this->actingAs($this->userWithRole('content-manager'))
            ->get('/admin')
            ->assertOk();
    }

    #[Test]
    public function sales_manager_still_enters_admin_despite_new_crm_permissions(): void
    {
        $this->actingAs($this->userWithRole('sales-manager'))
            ->get('/admin')
            ->assertOk();
    }

    #[Test]
    public function catalogist_still_enters_admin(): void
    {
        $this->actingAs($this->userWithRole('catalogist'))
            ->get('/admin')
            ->assertOk();
    }

    #[Test]
    public function super_admin_enters_admin(): void
    {
        $this->actingAs($this->userWithRole('super-admin'))
            ->get('/admin')
            ->assertOk();
    }

    #[Test]
    public function storefront_exposes_staff_admin_and_crm_flags_for_sales_head(): void
    {
        $this->actingAs($this->userWithRole('sales-head'))
            ->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                // is_staff остаётся true: витрина показывает сотруднику цены
                // независимо от status — этот гейт менять было нельзя.
                ->where('auth.user.is_staff', true)
                ->where('auth.user.is_admin', false)
                ->where('auth.user.is_crm', true)
            );
    }

    #[Test]
    public function storefront_flags_for_content_manager(): void
    {
        $this->actingAs($this->userWithRole('content-manager'))
            ->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.user.is_staff', true)
                ->where('auth.user.is_admin', true)
                ->where('auth.user.is_crm', false)
            );
    }

    #[Test]
    public function storefront_flags_for_sales_manager(): void
    {
        $this->actingAs($this->userWithRole('sales-manager'))
            ->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.user.is_staff', true)
                ->where('auth.user.is_admin', true)
                ->where('auth.user.is_crm', true)
            );
    }

    #[Test]
    public function storefront_flags_for_plain_client(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.user.is_staff', false)
                ->where('auth.user.is_admin', false)
                ->where('auth.user.is_crm', false)
            );
    }
}
