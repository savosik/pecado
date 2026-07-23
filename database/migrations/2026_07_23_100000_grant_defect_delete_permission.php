<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Право `defects.delete` — удаление партии уценки из админки.
 *
 * Раньше удалять партию мог только склад (`wms-defects.delete`). Закупщику
 * это тоже нужно: ошибочно заведённые партии всплывают именно на этапе
 * назначения цены.
 *
 * Назначаем точечно через givePermissionTo — сидер использует syncPermissions
 * и снёс бы текущий набор `buyer-manager` (роль заведена вручную на prod).
 */
return new class extends Migration
{
    private const PERMISSION = 'defects.delete';

    /** @var string[] */
    private const ROLES = ['buyer-manager', 'super-admin'];

    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::firstOrCreate([
            'name' => self::PERMISSION,
            'guard_name' => 'web',
        ]);

        foreach (self::ROLES as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

            // Роли может не быть на свежей БД — сидер создаст её позже.
            if (! $role) {
                continue;
            }

            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Удаление права снимает его со всех ролей каскадом.
        Permission::where('name', self::PERMISSION)->where('guard_name', 'web')->delete();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
