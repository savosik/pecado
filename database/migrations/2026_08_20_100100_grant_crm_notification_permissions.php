<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Права пульта уведомлений и адресной книги контрагентов.
 *
 * Сидер ролей на деплое не перезапускается, поэтому новые права раздаёт миграция —
 * иначе раздел был бы невидим всему отделу продаж.
 *
 * Разграничение: менеджер ведёт правила своих партнёров и их контакты, а правила
 * «для всех партнёров» и системные — только РОП (crm-notifications-all). Массовая
 * рассылка по всей базе не должна быть в руках одного менеджера.
 *
 * Идемпотентно, существующие права ролей не трогаем.
 */
return new class extends Migration
{
    private const MANAGER_PERMISSIONS = [
        'crm-notifications.view',
        'crm-notifications.create',
        'crm-notifications.edit',
        'crm-notifications.delete',
        'crm-notification-contacts.view',
        'crm-notification-contacts.create',
        'crm-notification-contacts.edit',
        'crm-notification-contacts.delete',
    ];

    private const HEAD_ONLY_PERMISSIONS = [
        'crm-notifications-all.view',
        'crm-notifications-all.edit',
    ];

    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $all = array_merge(self::MANAGER_PERMISSIONS, self::HEAD_ONLY_PERMISSIONS);

        foreach ($all as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
        }

        $grants = [
            'super-admin' => $all,
            'sales-manager' => self::MANAGER_PERMISSIONS,
            'sales-manager-crm' => self::MANAGER_PERMISSIONS,
            'sales-head' => $all,
        ];

        foreach ($grants as $roleName => $permissionNames) {
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

        Permission::whereIn('name', array_merge(self::MANAGER_PERMISSIONS, self::HEAD_ONLY_PERMISSIONS))
            ->where('guard_name', 'web')
            ->delete();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
