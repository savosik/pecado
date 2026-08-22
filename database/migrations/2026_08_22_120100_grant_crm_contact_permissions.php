<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Права раздела «Контакты» на боевом контуре.
 *
 * Сидер ролей на деплое не перезапускается, поэтому без миграции право появится
 * в коде, а на проде его не будет — и раздел молча не откроется ни у кого.
 *
 * Имя ресурса `crm-contacts` свободно: старые `crm-notification-contacts.*`
 * снесены вместе с пультом уведомлений (mail-10).
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'crm-contacts.view',
        'crm-contacts.create',
        'crm-contacts.edit',
        'crm-contacts.delete',
    ];

    /**
     * Кому что достаётся. Менеджеры ведут справочник наравне с РОПом: контакт
     * заводит тот, кто разговаривал с человеком, а границы всё равно задаёт
     * скоуп партнёров.
     */
    private const GRANTS = [
        'super-admin' => self::PERMISSIONS,
        'sales-manager' => self::PERMISSIONS,
        'sales-manager-crm' => self::PERMISSIONS,
        'sales-head' => self::PERMISSIONS,
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (self::GRANTS as $roleName => $permissions) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

            if ($role === null) {
                continue;
            }

            foreach ($permissions as $permission) {
                if (! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', self::PERMISSIONS)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
