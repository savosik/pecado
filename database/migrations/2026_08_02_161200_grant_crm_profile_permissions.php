<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Права на профиль клиента и жизненный статус.
 *
 * Сидер ролей на деплое не перезапускается, поэтому новые права раздаёт миграция —
 * иначе на dev и проде вкладка «Профиль» была бы невидима всему отделу продаж.
 *
 * Отдельного ресурса под жизненный статус нет: он живёт в том же профиле,
 * и `crm-profile.edit` покрывает и анкету, и смену стадии.
 *
 * Идемпотентно, существующие права ролей не трогаем.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'crm-profile.view',
        'crm-profile.edit',
    ];

    /** @var array<string, string[]> */
    private const GRANTS = [
        'super-admin' => self::PERMISSIONS,
        'sales-manager' => self::PERMISSIONS,
        'sales-manager-crm' => self::PERMISSIONS,
        'sales-head' => self::PERMISSIONS,
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

        Permission::whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
