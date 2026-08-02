<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Права на комментарии CRM.
 *
 * Раздаются всем ролям отдела продаж: комментарий — базовый рабочий инструмент менеджера,
 * а не привилегия. Ограничение «править и удалять можно только своё» живёт в CrmCommentPolicy,
 * а не в правах: право delete нужно и рядовому менеджеру для своих же записей.
 *
 * Идемпотентно, существующие права ролей не трогаем.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'crm-comments.view',
        'crm-comments.create',
        'crm-comments.edit',
        'crm-comments.delete',
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
