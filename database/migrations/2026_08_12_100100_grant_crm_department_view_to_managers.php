<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

/**
 * Взаимозаменяемость менеджеров, чтение (crm-21, этап 4).
 *
 * Менеджеры получают охват всего отдела: карточки партнёров коллег, их задачи,
 * звонки, письма и документы. Это организационное решение — отдел переходит
 * к взаимозаменяемости, менеджер должен уметь подменить коллегу.
 *
 * Экран при этом не «вываливает всё»: разрез по умолчанию — «только мои»
 * ({@see \App\Enums\Crm\CrmScope}), расфокус остаётся осознанным действием.
 *
 * Разрезы по менеджерам (чужая выручка, план отдела, сетка менеджеров)
 * сюда НЕ входят — они остаются за `crm-clients-all.view` у роли sales-head.
 *
 * Откат: снять право у ролей. Данные не затрагиваются.
 */
return new class extends Migration
{
    private const PERMISSION = 'crm-department.view';

    private const ROLES = ['sales-manager', 'sales-manager-crm'];

    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::ROLES as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

            if ($role && ! $role->hasPermissionTo(self::PERMISSION)) {
                $role->givePermissionTo(self::PERMISSION);
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::ROLES as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

            if ($role && $role->hasPermissionTo(self::PERMISSION)) {
                $role->revokePermissionTo(self::PERMISSION);
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
