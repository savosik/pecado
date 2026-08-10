<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Права на настройки интеграции с ApiShip.
 *
 * Только начальнику склада: там лежат токен доступа к боевому API перевозчиков
 * и адрес отправителя, от которого считаются все тарифы. Кладовщику эти поля
 * не нужны, а сломать ими можно весь раздел разом.
 */
return new class extends Migration
{
    /** @var array<string, string[]> */
    private const GRANTS = [
        'warehouse-head' => [
            'wms-delivery-settings.view',
            'wms-delivery-settings.edit',
        ],
    ];

    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $roleNames = array_merge(array_keys(self::GRANTS), ['super-admin']);
        $allPermissions = array_unique(array_merge(...array_values(self::GRANTS)));

        foreach ($roleNames as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

            if (! $role) {
                continue;
            }

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
