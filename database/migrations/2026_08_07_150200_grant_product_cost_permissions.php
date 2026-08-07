<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * US-18 (v15.13.0): право на просмотр себестоимости товаров.
 *
 * Отдельное от `products.*`: карточку товара ведёт каталоговед, а во сколько товар
 * обошёлся — знать ему незачем. Право получают закупщик (ведёт цены и уценку),
 * руководитель отдела продаж (маржа) и супер-админ.
 *
 * Роль `buyer-manager` заведена на prod вручную и сидером не покрывается, поэтому
 * выдача точечная и идемпотентная (givePermissionTo) — прогон
 * RolesAndPermissionsSeeder с его syncPermissions на prod не требуется.
 */
return new class extends Migration
{
    /** @var array<string, string[]> */
    private const GRANTS = [
        'buyer-manager' => ['product-costs.view'],
        'sales-head' => ['product-costs.view'],
    ];

    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $roleNames = array_merge(array_keys(self::GRANTS), ['super-admin']);
        $allPermissions = array_unique(array_merge(...array_values(self::GRANTS)));

        foreach ($roleNames as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

            // Роли может не быть на свежей БД — сидер создаст её позже со своим набором.
            if (! $role) {
                continue;
            }

            $permissionNames = $roleName === 'super-admin'
                ? $allPermissions
                : self::GRANTS[$roleName];

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

        Permission::whereIn('name', array_unique(array_merge(...array_values(self::GRANTS))))
            ->where('guard_name', 'web')
            ->delete();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
