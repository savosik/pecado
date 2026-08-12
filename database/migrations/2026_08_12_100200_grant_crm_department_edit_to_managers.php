<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

/**
 * Взаимозаменяемость менеджеров, действия (crm-21, этап 5).
 *
 * Менеджер может не только видеть работу коллеги, но и помогать её делать:
 * править и закрывать чужие задачи, править чужие комментарии и звонки.
 * Без этого «видеть задачи других и помогать их выполнять» осталось бы
 * половиной требования.
 *
 * Отдельной миграцией от этапа 4 намеренно: если расширение прав на запись
 * окажется преждевременным, оно откатывается само по себе, а видимость отдела
 * остаётся.
 *
 * Сознательно НЕ входит:
 *   - удаление чужих вложений (`AttachmentController`) — деструктивно
 *     и без отката, файл восстановить неоткуда;
 *   - чужие черновики писем — черновик это не запись о клиенте,
 *     а недописанная мысль, и он виден только автору.
 */
return new class extends Migration
{
    private const PERMISSION = 'crm-department.edit';

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
