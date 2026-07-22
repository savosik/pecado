<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Права на справочник типовых дефектов.
 *
 * Управляет справочником закупщик (buyer-manager) в /admin — как и ценами уценки.
 * Идемпотентно, существующие права ролей не трогаем (см. grant_defect_permissions).
 */
return new class extends Migration
{
    /** @var array<string, string[]> */
    private const GRANTS = [
        'buyer-manager' => ['defect-types.view', 'defect-types.create', 'defect-types.edit', 'defect-types.delete'],
        'super-admin' => ['defect-types.view', 'defect-types.create', 'defect-types.edit', 'defect-types.delete'],
    ];

    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::GRANTS as $roleName => $permissionNames) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

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

        Permission::whereIn('name', [
            'defect-types.view', 'defect-types.create', 'defect-types.edit', 'defect-types.delete',
        ])->where('guard_name', 'web')->delete();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
