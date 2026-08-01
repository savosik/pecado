<?php

namespace Tests\Feature\Admin;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Справочник организаций в админке (карточка org-01).
 *
 * Ключевое отличие от прочих справочников — заглушки: их видно в списке, а сохранение
 * админом снимает пометку.
 */
class OrganizationCrudTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $role = Role::firstOrCreate(['name' => 'org-admin', 'guard_name' => 'web']);

        foreach (['organizations.view', 'organizations.create', 'organizations.edit', 'organizations.delete'] as $name) {
            $role->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        }

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    #[Test]
    public function store_creates_organization_with_external_id(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.organizations.store'), [
                'name' => 'ООО Пекадо',
                'external_id' => '3d0a3eb9-0c23-11ee-8ddc-ee348b24c7ce',
                'tax_id' => '7712345678',
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('organizations', [
            'name' => 'ООО Пекадо',
            'external_id' => '3d0a3eb9-0c23-11ee-8ddc-ee348b24c7ce',
            'is_stub' => false,
        ]);
    }

    #[Test]
    public function store_rejects_duplicate_external_id_with_russian_message(): void
    {
        Organization::factory()->create(['external_id' => 'duplicate-uuid']);

        $this->actingAs($this->admin())
            ->post(route('admin.organizations.store'), [
                'name' => 'ООО Дубль',
                'external_id' => 'duplicate-uuid',
            ])
            ->assertSessionHasErrors(['external_id' => 'Организация с таким UUID из 1С уже заведена.']);
    }

    #[Test]
    public function store_requires_name_with_russian_message(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.organizations.store'), ['name' => ''])
            ->assertSessionHasErrors(['name' => 'Укажите название организации.']);
    }

    #[Test]
    public function update_clears_stub_flag(): void
    {
        $stub = Organization::factory()->stub()->create(['external_id' => 'stub-uuid']);

        $this->actingAs($this->admin())
            ->put(route('admin.organizations.update', $stub->id), [
                'name' => 'ООО Реклама',
                'external_id' => 'stub-uuid',
                'bank_name' => 'Сбербанк',
                'is_active' => true,
            ])
            ->assertRedirect();

        $fresh = $stub->fresh();
        $this->assertFalse($fresh->is_stub);
        $this->assertSame('ООО Реклама', $fresh->name);
    }

    #[Test]
    public function update_keeps_own_external_id_without_unique_error(): void
    {
        $organization = Organization::factory()->create(['external_id' => 'own-uuid']);

        $this->actingAs($this->admin())
            ->put(route('admin.organizations.update', $organization->id), [
                'name' => 'ООО Пекадо',
                'external_id' => 'own-uuid',
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function index_reports_stub_count_and_sorts_stubs_first(): void
    {
        Organization::factory()->create(['name' => 'ООО Пекадо', 'sort_order' => 0]);
        Organization::factory()->stub()->create(['external_id' => 'stub-uuid']);

        $this->actingAs($this->admin())
            ->get(route('admin.organizations.index'))
            ->assertInertia(fn ($page) => $page
                ->where('stubCount', 1)
                ->where('organizations.data.0.is_stub', true)
            );
    }

    #[Test]
    public function destroy_soft_deletes_organization(): void
    {
        $organization = Organization::factory()->create();

        $this->actingAs($this->admin())
            ->delete(route('admin.organizations.destroy', $organization->id))
            ->assertRedirect(route('admin.organizations.index'));

        $this->assertSoftDeleted('organizations', ['id' => $organization->id]);
    }

    /**
     * Справочник юрлиц ведёт админ — сотруднику с другими админскими правами он закрыт.
     *
     * Право `products.view` здесь нужно, чтобы пройти EnsureUserIsAdmin: без единого
     * админского права пользователя редиректит с /admin ещё до проверки permission.
     */
    #[Test]
    public function admin_without_organizations_permission_cannot_view_directory(): void
    {
        $role = Role::firstOrCreate(['name' => 'catalog-only', 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::firstOrCreate(['name' => 'products.view', 'guard_name' => 'web']));

        $user = User::factory()->create();
        $user->assignRole($role);

        $this->actingAs($user)
            ->get(route('admin.organizations.index'))
            ->assertForbidden();
    }
}
