<?php

namespace Tests\Feature\Crm;

use App\Http\Controllers\Admin\RoleController;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use ReflectionClass;
use Tests\TestCase;

/**
 * Страж соглашения об именах прав.
 *
 * hasAdminAccess()/hasCrmAccess() отличают домены по префиксу `crm-`,
 * поэтому имя ресурса — часть контракта, а не косметика.
 *
 * Заодно ловит дрейф двух справочников: RolesAndPermissionsSeeder::$resources
 * (источник истины) и RoleController::$permissionGroups (UI матрицы прав).
 */
class PermissionNamingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function seederResources(): array
    {
        $property = (new ReflectionClass(RolesAndPermissionsSeeder::class))->getProperty('resources');

        return $property->getValue(new RolesAndPermissionsSeeder);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function controllerGroups(): array
    {
        $property = (new ReflectionClass(RoleController::class))->getProperty('permissionGroups');

        return $property->getValue(new RoleController);
    }

    #[Test]
    #[TestDox('Все ресурсы группы CRM несут префикс crm-')]
    public function crm_group_resources_carry_the_prefix(): void
    {
        $crmGroup = $this->controllerGroups()['CRM'] ?? null;

        $this->assertNotNull($crmGroup, 'В RoleController нет группы «CRM».');

        foreach ($crmGroup as $resource) {
            $this->assertStringStartsWith(
                User::CRM_PERMISSION_PREFIX,
                $resource,
                "Ресурс «{$resource}» в группе CRM обязан начинаться с префикса."
            );
        }
    }

    #[Test]
    #[TestDox('Ни один не-CRM ресурс не начинается с crm-')]
    public function non_crm_resources_do_not_use_the_prefix(): void
    {
        $crmGroup = $this->controllerGroups()['CRM'] ?? [];

        foreach (array_keys($this->seederResources()) as $resource) {
            if (in_array($resource, $crmGroup, true)) {
                continue;
            }

            $this->assertStringStartsNotWith(
                User::CRM_PERMISSION_PREFIX,
                $resource,
                "Ресурс «{$resource}» не относится к CRM, но носит её префикс — hasAdminAccess() перестанет пускать в админку."
            );
        }
    }

    #[Test]
    #[TestDox('Каждый CRM-ресурс есть и в сидере, и в UI матрицы прав')]
    public function crm_resources_exist_in_both_registries(): void
    {
        $seederResources = $this->seederResources();

        foreach ($this->controllerGroups()['CRM'] ?? [] as $resource) {
            $this->assertArrayHasKey(
                $resource,
                $seederResources,
                "Ресурс «{$resource}» есть в RoleController, но отсутствует в сидере — права не создадутся."
            );
        }
    }

    #[Test]
    #[TestDox('Каждый ресурс сидера доступен в UI матрицы прав')]
    public function every_seeder_resource_is_exposed_in_the_roles_ui(): void
    {
        $exposed = array_merge(...array_values($this->controllerGroups()));

        foreach (array_keys($this->seederResources()) as $resource) {
            $this->assertContains(
                $resource,
                $exposed,
                "Ресурс «{$resource}» есть в сидере, но его нет ни в одной группе RoleController — права нельзя выдать через UI."
            );
        }
    }

    #[Test]
    #[TestDox('Роль sales-head получает только CRM-права')]
    public function sales_head_is_crm_only(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $head = User::factory()->create();
        $head->assignRole('sales-head');

        $this->assertTrue($head->hasCrmAccess());
        $this->assertFalse($head->hasAdminAccess());
    }

    #[Test]
    #[TestDox('Роль sales-manager-crm получает только CRM-права')]
    public function sales_manager_crm_is_crm_only(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('sales-manager-crm');

        $this->assertTrue($manager->hasCrmAccess());
        $this->assertFalse($manager->hasAdminAccess(), 'Боевые менеджеры не должны попадать в админку.');
        // Клиентов всего отдела не видит — только своих.
        $this->assertFalse($manager->can('crm-clients-all.view'));
        $this->assertFalse($manager->can('crm-team.view'));
    }
}
