<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Права на раздел CRM «Недоборы» (подборки замен по отменам строк 1С).
 *
 * Сидер ролей на деплое не перезапускается, поэтому новые права раздаёт миграция.
 * view — очередь и карточка, edit — кандидаты, письмо и исходы.
 * Границы внутри права задаёт скоуп клиентов, как в остальных разделах CRM.
 *
 * Идемпотентно, существующие права ролей не трогаем.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'crm-shortages.view',
        'crm-shortages.edit',
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

        foreach (self::PERMISSIONS as $permissionName) {
            Permission::where('name', $permissionName)->where('guard_name', 'web')->delete();
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
