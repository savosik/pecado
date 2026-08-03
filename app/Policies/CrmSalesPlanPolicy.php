<?php

namespace App\Policies;

use App\Models\CrmSalesPlan;
use App\Models\User;
use App\Services\Crm\SalesPlanService;

/**
 * Доступ к планам продаж.
 *
 * Право `crm-plans.edit` есть у всего отдела, но границы задаёт скоуп: менеджер
 * расписывает только своих клиентов, план отдела и планы менеджеров ставит тот,
 * кто отвечает за отдел целиком. Правило живёт в `SalesPlanService::canManage()`
 * и отсюда только вызывается — вторая копия этой логики неизбежно разошлась бы
 * с первой.
 *
 * Суперадмин проходит бесплатно через Gate::before в AppServiceProvider.
 */
class CrmSalesPlanPolicy
{
    public function __construct(private readonly SalesPlanService $plans) {}

    public function viewAny(User $user): bool
    {
        return $user->can('crm-plans.view');
    }

    public function view(User $user, CrmSalesPlan $plan): bool
    {
        return $user->can('crm-plans.view')
            && $this->plans->visibleTo($user)->whereKey($plan->getKey())->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('crm-plans.create');
    }

    public function update(User $user, CrmSalesPlan $plan): bool
    {
        return $this->manages($user, $plan);
    }

    public function delete(User $user, CrmSalesPlan $plan): bool
    {
        return $user->can('crm-plans.delete') && $this->manages($user, $plan);
    }

    private function manages(User $user, CrmSalesPlan $plan): bool
    {
        $targetId = $plan->target_type->needsTarget() ? $plan->target_id : null;

        return $this->plans->canManage($user, $plan->target_type, $targetId);
    }
}
