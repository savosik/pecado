<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Права на справочник причин недоборов (short-02).
 *
 * Сидер ролей на деплое не перезапускается, поэтому новые права раздаёт миграция.
 * Менеджеру — только view: причину он выбирает из списка, но сам список не правит,
 * иначе в сводке разведутся синонимы одной и той же причины. Ведёт справочник РОП.
 *
 * Идемпотентно, существующие права ролей не трогаем.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'crm-shortage-reasons.view',
        'crm-shortage-reasons.create',
        'crm-shortage-reasons.edit',
        'crm-shortage-reasons.delete',
    ];

    /** @var array<string, list<string>> */
    private const GRANTS = [
        'super-admin' => self::PERMISSIONS,
        'sales-head' => self::PERMISSIONS,
        'sales-manager' => ['crm-shortage-reasons.view'],
        'sales-manager-crm' => ['crm-shortage-reasons.view'],
    ];

    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
        }

        foreach (self::GRANTS as $roleName => $permissionNames) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

            if (! $role) {
                continue;
            }

            foreach ($permissionNames as $permissionName) {
                if (! $role->hasPermissionTo($permissionName)) {
                    $role->givePermissionTo($permissionName);
                }
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permissionName) {
            Permission::where('name', $permissionName)->where('guard_name', 'web')->delete();
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
