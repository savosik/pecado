<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Права на журнал расходных ордеров в кабинете склада (US-20).
 *
 * Ордера смотрят обе складские роли — начальник склада и кладовщик: это их основной
 * рабочий документ. Выгрузку в Excel даём обеим по той же причине (сверка при погрузке
 * и передача экспедитору), разводить тут нечего.
 *
 * Выдача точечная и идемпотентная (givePermissionTo), чтобы на prod не требовался прогон
 * RolesAndPermissionsSeeder с его syncPermissions.
 */
return new class extends Migration
{
    /** @var array<string, string[]> */
    private const GRANTS = [
        'warehouse-head' => [
            'wms-goods-issues.view',
            'wms-goods-issues.export',
        ],
        'storekeeper' => [
            'wms-goods-issues.view',
            'wms-goods-issues.export',
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
