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

    /**
     * Группы RoleController, относящиеся к панелям, и их префиксы.
     *
     * @return array<string, string>
     */
    private function panelGroups(): array
    {
        return [
            'CRM' => User::CRM_PERMISSION_PREFIX,
            'Склад (WMS)' => User::WMS_PERMISSION_PREFIX,
        ];
    }

    #[Test]
    #[TestDox('Все ресурсы панельных групп несут свой префикс')]
    public function panel_group_resources_carry_their_prefix(): void
    {
        foreach ($this->panelGroups() as $group => $prefix) {
            $resources = $this->controllerGroups()[$group] ?? null;

            $this->assertNotNull($resources, "В RoleController нет группы «{$group}».");

            foreach ($resources as $resource) {
                $this->assertStringStartsWith(
                    $prefix,
                    $resource,
                    "Ресурс «{$resource}» в группе «{$group}» обязан начинаться с префикса «{$prefix}»."
                );
            }
        }
    }

    #[Test]
    #[TestDox('Ни один непанельный ресурс не носит панельный префикс')]
    public function non_panel_resources_do_not_use_panel_prefixes(): void
    {
        // Ресурсы всех панельных групп разом — они законно носят префиксы.
        $panelResources = array_merge(
            ...array_map(
                fn (string $group) => $this->controllerGroups()[$group] ?? [],
                array_keys($this->panelGroups())
            )
        );

        foreach (array_keys($this->seederResources()) as $resource) {
            if (in_array($resource, $panelResources, true)) {
                continue;
            }

            foreach (User::PANEL_PERMISSION_PREFIXES as $prefix) {
                $this->assertStringStartsNotWith(
                    $prefix,
                    $resource,
                    "Ресурс «{$resource}» не относится к панелям, но носит префикс «{$prefix}» — hasAdminAccess() перестанет пускать его владельцев в админку."
                );
            }
        }
    }

    #[Test]
    #[TestDox('Каждый панельный префикс объявлен в PANEL_PERMISSION_PREFIXES')]
    public function every_panel_prefix_is_registered(): void
    {
        // Забыть префикс в константе — значит открыть владельцам этих прав
        // доступ в /admin, и ни один тест доступа этого бы не заметил.
        foreach ($this->panelGroups() as $group => $prefix) {
            $this->assertContains(
                $prefix,
                User::PANEL_PERMISSION_PREFIXES,
                "Префикс «{$prefix}» группы «{$group}» не объявлен в User::PANEL_PERMISSION_PREFIXES."
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

    #[Test]
    #[TestDox('Складские роли получают только WMS-права')]
    public function warehouse_roles_are_wms_only(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        foreach (['warehouse-head', 'storekeeper'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            $this->assertTrue($user->hasWmsAccess(), "Роль «{$role}» должна давать доступ в /wms.");
            $this->assertFalse($user->hasAdminAccess(), "Роль «{$role}» не должна пускать в /admin.");
            $this->assertFalse($user->hasCrmAccess(), "Роль «{$role}» не должна пускать в /crm.");
        }
    }
}
