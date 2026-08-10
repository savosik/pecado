<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Права на раздел отправок в кабинете склада (ApiShip).
 *
 * Собирают и отправляют груз обе роли — этим и занимается склад. Разведена только
 * отмена: отмена заявки в ТК может стоить денег и разъезжается с уже напечатанными
 * этикетками, поэтому остаётся за начальником склада.
 *
 * Префикс `wms-` обязателен: User::hasAdminAccess() считает непанельным любое право
 * без `wms-`/`crm-`, и право с другим именем молча открыло бы складским ролям /admin.
 *
 * Выдача точечная и идемпотентная (givePermissionTo), чтобы на prod не требовался
 * прогон RolesAndPermissionsSeeder с его syncPermissions.
 */
return new class extends Migration
{
    /** @var array<string, string[]> */
    private const GRANTS = [
        'warehouse-head' => [
            'wms-deliveries.view',
            'wms-deliveries.create',
            'wms-deliveries.edit',
            'wms-deliveries.submit',
            'wms-deliveries.cancel',
        ],
        'storekeeper' => [
            'wms-deliveries.view',
            'wms-deliveries.create',
            'wms-deliveries.edit',
            'wms-deliveries.submit',
        ],
    ];

    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $roleNames = array_merge(array_keys(self::GRANTS), ['super-admin']);
        $allPermissions = array_unique(array_merge(...array_values(self::GRANTS)));

        foreach ($roleNames as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

            // Роли может не быть на свежей БД — сидер создаст её позже со своим набором.
            if (! $role) {
                continue;
            }

            // Супер-админ должен видеть новый раздел без ручных действий.
            $permissionNames = $roleName === 'super-admin'
                ? $allPermissions
                : self::GRANTS[$roleName];

            foreach ($permissionNames as $permissionName) {
                $permission = Permission::firstOrCreate([
                    'name' => $permissionName,
                    'guard_name' => 'web',
                ]);

                if (! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::whereIn('name', array_unique(array_merge(...array_values(self::GRANTS))))
            ->where('guard_name', 'web')
            ->delete();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
