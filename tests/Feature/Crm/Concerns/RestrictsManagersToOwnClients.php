<?php

namespace Tests\Feature\Crm\Concerns;

use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Сводит роли менеджеров к собственным партнёрам — состояние до crm-21.
 *
 * С волной 7 менеджеры стали взаимозаменяемыми: роль `sales-manager` получила
 * `crm-department.view`, и «чужой партнёр» для неё больше не чужой. Но сама
 * изоляция никуда не делась — это живой код, и РОП включает её обратно, снимая
 * право в матрице ролей.
 *
 * Тесты, предмет которых — механика изоляции (скоуп, 404 вместо 403, попытка
 * подставить чужой id), используют этот трейт: они проверяют, что граница
 * работает, когда её включили. Поведение расширенной видимости проверяется
 * отдельно — {@see \Tests\Feature\Crm\CrmScopeTest}.
 */
trait RestrictsManagersToOwnClients
{
    /**
     * @param  list<string>  $roles
     */
    protected function restrictManagersToOwnClients(
        array $roles = ['sales-manager', 'sales-manager-crm'],
    ): void {
        foreach ($roles as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

            if ($role?->hasPermissionTo('crm-department.view')) {
                $role->revokePermissionTo('crm-department.view');
            }

            if ($role?->hasPermissionTo('crm-department.edit')) {
                $role->revokePermissionTo('crm-department.edit');
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
