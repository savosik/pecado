<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Права WMS-раздела «Страховой запас» (buf-06) складским ролям.
 *
 * По образцу grant_defect_permissions: точечно и идемпотентно через
 * givePermissionTo, чтобы на prod не требовался прогон сидера.
 */
return new class extends Migration
{
    /** @var array<string, string[]> */
    private const GRANTS = [
        'storekeeper' => ['wms-stock-buffers.view', 'wms-stock-buffers.edit'],
        'warehouse-head' => ['wms-stock-buffers.view', 'wms-stock-buffers.edit'],
    ];

    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $grantAll = self::GRANTS + ['super-admin' => ['wms-stock-buffers.view', 'wms-stock-buffers.edit']];

        foreach ($grantAll as $roleName => $permissionNames) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

            // Роли может не быть на свежей БД — сидер создаст её позже со своим набором.
            if (! $role) {
                continue;
            }

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

        Permission::whereIn('name', ['wms-stock-buffers.view', 'wms-stock-buffers.edit'])
            ->where('guard_name', 'web')
            ->delete();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
