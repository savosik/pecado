<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Права на уценку существующим ролям.
 *
 * Роль `buyer-manager` (закупщик) заведена вручную на prod и намеренно отсутствует
 * в RolesAndPermissionsSeeder: сидер использует syncPermissions и снёс бы её текущий
 * набор админских прав. Поэтому доназначаем точечно и идемпотентно, через
 * givePermissionTo — существующие права ролей не трогаем.
 *
 * Складским ролям права тоже выдаём здесь, чтобы на prod не требовался прогон сидера.
 */
return new class extends Migration
{
    /** @var array<string, string[]> */
    private const GRANTS = [
        'buyer-manager' => ['defects.view', 'defects.price', 'defects.publish'],
        'storekeeper' => ['wms-defects.view', 'wms-defects.create', 'wms-defects.edit', 'wms-defects.delete'],
        'warehouse-head' => ['wms-defects.view', 'wms-defects.create', 'wms-defects.edit', 'wms-defects.delete'],
    ];

    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::GRANTS as $roleName => $permissionNames) {
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

        // Супер-админ должен видеть новые разделы без ручных действий.
        $superAdmin = Role::where('name', 'super-admin')->where('guard_name', 'web')->first();
        if ($superAdmin) {
            foreach (self::GRANTS as $permissionNames) {
                foreach ($permissionNames as $permissionName) {
                    $permission = Permission::firstOrCreate([
                        'name' => $permissionName,
                        'guard_name' => 'web',
                    ]);

                    if (! $superAdmin->hasPermissionTo($permission)) {
                        $superAdmin->givePermissionTo($permission);
                    }
                }
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $names = collect(self::GRANTS)->flatten()->unique()->all();

        // Удаление права снимает его со всех ролей каскадом.
        Permission::whereIn('name', $names)->where('guard_name', 'web')->delete();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
