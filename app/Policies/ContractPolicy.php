<?php

namespace App\Policies;

use App\Models\Contract;
use App\Models\User;
use App\Services\Crm\CrmEntityResolver;

/**
 * Доступ к договорам реестра.
 *
 * Граница — партнёр: кто видит партнёра, тот видит его договоры. Договор без
 * партнёра (иностранный поставщик, контрагент без привязки) — только тем, кто
 * видит отдел, иначе он всплывал бы у каждого менеджера.
 *
 * Суперадмин проходит бесплатно через Gate::before в AppServiceProvider.
 */
class ContractPolicy
{
    public function __construct(private readonly CrmEntityResolver $resolver) {}

    public function viewAny(User $user): bool
    {
        return $user->can('crm-contracts.view');
    }

    public function view(User $user, Contract $contract): bool
    {
        return $user->can('crm-contracts.view') && $this->accessible($user, $contract);
    }

    public function create(User $user): bool
    {
        return $user->can('crm-contracts.create');
    }

    public function update(User $user, Contract $contract): bool
    {
        return $user->can('crm-contracts.edit') && $this->accessible($user, $contract);
    }

    public function delete(User $user, Contract $contract): bool
    {
        return $user->can('crm-contracts.delete') && $this->accessible($user, $contract);
    }

    private function accessible(User $user, Contract $contract): bool
    {
        if ($contract->user_id === null) {
            return $user->can('crm-department.view');
        }

        return $this->resolver->clientVisible($user, (int) $contract->user_id);
    }
}
