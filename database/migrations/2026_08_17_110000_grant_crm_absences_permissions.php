<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Права на разделы CRM «Отсутствия» и «Табель» (эпик abs-00).
 *
 * Сидер ролей на деплое не перезапускается, поэтому новые права раздаёт миграция.
 * Отсутствия: view — у всего отдела (кто кого замещает — рабочая информация),
 * edit — только РОП. Табель — только РОП: прогулы коллег не для всеобщего
 * обозрения.
 *
 * Идемпотентно, существующие права ролей не трогаем.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'crm-absences.view',
        'crm-absences.edit',
        'crm-timesheet.view',
    ];

    /** @var array<string, string[]> */
    private const GRANTS = [
        'super-admin' => self::PERMISSIONS,
        'sales-manager' => ['crm-absences.view'],
        'sales-manager-crm' => ['crm-absences.view'],
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
