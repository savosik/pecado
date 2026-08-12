<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Расщепление права «вижу весь отдел» (crm-21, этап 1).
 *
 * До этой миграции `crm-clients-all.view` означало сразу четыре вещи: вижу
 * партнёров отдела, действую с чужими записями, мне доступен фильтр по менеджерам
 * и вижу чужую выручку. Из-за склейки видимость отдела нельзя было выдать
 * менеджеру, не отдав заодно право менять план отдела и удалять чужие вложения.
 *
 * Появляется `crm-department.view|edit`. `crm-clients-all.view` остаётся
 * РОПовским и отвечает теперь только за разрезы по менеджерам, план отдела
 * и чужую выручку.
 *
 * Поведение не меняется: право выдаётся ровно тем, у кого уже есть
 * `crm-clients-all.view`. Менеджеры получат его отдельной миграцией (этап 4),
 * чтобы расширение видимости откатывалось само по себе.
 *
 * Идемпотентно, существующие права ролей не трогаем.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'crm-department.view',
        'crm-department.edit',
    ];

    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
        }

        // Наследуем ровно тем, кто и так видел отдел: суперадмин проходит через
        // Gate::before, но право ему выдаётся явно — чтобы матрица ролей
        // не показывала пустую строку у роли, которая фактически всё может.
        $inheritors = Role::where('guard_name', 'web')
            ->whereHas('permissions', fn ($q) => $q->where('name', 'crm-clients-all.view'))
            ->get();

        foreach ($inheritors as $role) {
            foreach (self::PERMISSIONS as $permissionName) {
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
