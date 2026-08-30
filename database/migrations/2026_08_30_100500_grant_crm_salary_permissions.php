<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Права на раздел зарплаты в CRM (эпик pay-00).
 *
 * Сидер ролей на деплое не перезапускается, поэтому новые права раздаёт миграция.
 * `view` — своя зарплата, у всего отдела; чужие и сводка отдела дополнительно
 * требуют crm-clients-all.view («вижу чужие деньги» — то же правило, что у планов).
 * `edit` — параметры, корректировки, разметка накладных, утверждение: только РОП.
 *
 * Идемпотентно, существующие права ролей не трогаем.
 */
return new class extends Migration
{
    /** @var array<string, string[]> */
    private const GRANTS = [
        'super-admin' => ['crm-salary.view', 'crm-salary.edit'],
        'sales-manager' => ['crm-salary.view'],
        'sales-manager-crm' => ['crm-salary.view'],
        'sales-head' => ['crm-salary.view', 'crm-salary.edit'],
    ];

    private const PERMISSIONS = ['crm-salary.view', 'crm-salary.edit'];

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
