<?php

namespace App\Services\Payroll;

use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Crm\PlanScopeResolver;

/**
 * Чью зарплату показываем.
 *
 * Менеджер — только свою: чужой `manager` в адресе игнорируется, а не проверяется.
 * РОП (crm-clients-all.view) — любого; без параметра — свою карточку, если она есть,
 * иначе экран «выберите менеджера». То же правило, что у планов и аналитики.
 */
class PayrollScopeResolver
{
    public function __construct(private readonly PlanScopeResolver $planScopes) {}

    public function manager(User $actor, ?int $requestedId): ?PersonalManager
    {
        $seesAll = $actor->can('crm-clients-all.view');

        if ($seesAll && $requestedId !== null) {
            return PersonalManager::query()->find($requestedId);
        }

        return $actor->managerProfile;
    }

    public function seesAll(User $actor): bool
    {
        return $actor->can('crm-clients-all.view');
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function options(User $actor): array
    {
        return $this->planScopes->options($actor);
    }
}
