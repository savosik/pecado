<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Права на журнал «Предзаказы поставщику» существующим ролям.
 *
 * Назначаем точечно (givePermissionTo), чтобы на prod не требовался прогон
 * RolesAndPermissionsSeeder — он работает через syncPermissions и снёс бы
 * наборы ролей, заведённых вручную.
 */
return new class extends Migration
{
    /** @var array<string, string[]> */
    private const GRANTS = [
        'super-admin' => ['supplier-preorders.view', 'supplier-preorders.send'],
        // Менеджер продаж отвечает за предзаказ перед клиентом: смотрит журнал
        // и при сбое переотправляет заказ поставщику.
        'sales-manager' => ['supplier-preorders.view', 'supplier-preorders.send'],
        // Закупщик заведён вручную на prod — ему журнал нужен на чтение.
        'buyer-manager' => ['supplier-preorders.view'],
    ];

    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::GRANTS as $roleName => $permissionNames) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

            // Роли может не быть на свежей БД — сидер создаст её позже со своим набором.
            if (! $role) {
                continue;
            }

            foreach ($permissionNames as $permissionName) {
                $permission = Permission::firstOrCreate([
                    'name' => $permissionName,
                    'guard_name' => 'web',
                ]);

                if (! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $names = collect(self::GRANTS)->flatten()->unique()->all();

        Permission::whereIn('name', $names)->where('guard_name', 'web')->delete();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
