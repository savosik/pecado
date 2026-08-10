<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Право на раздел «Контрагенты» в CRM (юрлица партнёров).
 *
 * Сидер ролей на деплое не перезапускается, поэтому новые права раздаёт миграция —
 * иначе раздел был бы невидим всему отделу продаж.
 *
 * Только view: реквизиты контрагента ведёт 1С, в CRM карточка нужна для задач,
 * комментариев и файлов. Границы задаёт скоуп партнёров — менеджер видит юрлица
 * только своих, контрагента без партнёра видит лишь тот, у кого crm-clients-all.view.
 *
 * Идемпотентно, существующие права ролей не трогаем.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'crm-contractors.view',
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
