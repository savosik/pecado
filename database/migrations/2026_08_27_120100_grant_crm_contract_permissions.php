<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Права раздела «Договоры» на боевом контуре.
 *
 * Сидер ролей на деплое не перезапускается, поэтому без миграции право появится
 * в коде, а на проде его не будет — и раздел молча не откроется ни у кого.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'crm-contracts.view',
        'crm-contracts.create',
        'crm-contracts.edit',
        'crm-contracts.delete',
    ];

    /**
     * Менеджеры заводят и правят договоры своих партнёров сами — так же, как
     * вели таблицу. Удаление оставлено РОПу: за договором стоят сканы и задачи.
     */
    private const GRANTS = [
        'super-admin' => self::PERMISSIONS,
        'sales-manager' => ['crm-contracts.view', 'crm-contracts.create', 'crm-contracts.edit'],
        'sales-manager-crm' => ['crm-contracts.view', 'crm-contracts.create', 'crm-contracts.edit'],
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
