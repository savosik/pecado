<?php

namespace App\Policies;

use App\Models\CrmCall;
use App\Models\User;
use App\Services\Crm\CrmEntityResolver;

/**
 * Доступ к звонкам CRM.
 *
 * Модель доступа как у комментария, а не как у задачи: звонок по партнёру — общий
 * факт его жизни, а не поручение между сотрудниками. Коллега, поднявший трубку
 * за отпускника, должен видеть, о чём с партнёром уже говорили.
 *
 * Право `crm-calls.*` даёт возможность работать со звонками, но не отменяет скоуп
 * партнёров: без проверки видимости менеджер, зная ID, читал бы историю разговоров
 * по чужому партнёру в обход `User::visibleInCrm()`.
 *
 * Суперадмин проходит бесплатно через Gate::before в AppServiceProvider.
 */
class CrmCallPolicy
{
    public function __construct(private readonly CrmEntityResolver $resolver) {}

    public function viewAny(User $user): bool
    {
        return $user->can('crm-calls.view');
    }

    public function view(User $user, CrmCall $call): bool
    {
        return $user->can('crm-calls.view') && $this->clientAccessible($user, $call);
    }

    public function create(User $user): bool
    {
        return $user->can('crm-calls.create');
    }

    /**
     * Править можно свою запись; РОП — любую в отделе (нужно, чтобы поправить
     * чужую опечатку в итоге разговора, пока автор в отпуске).
     */
    public function update(User $user, CrmCall $call): bool
    {
        return $user->can('crm-calls.edit')
            && $this->clientAccessible($user, $call)
            && $this->ownsOrLeads($user, $call);
    }

    public function delete(User $user, CrmCall $call): bool
    {
        return $user->can('crm-calls.delete')
            && $this->clientAccessible($user, $call)
            && $this->ownsOrLeads($user, $call);
    }

    private function ownsOrLeads(User $user, CrmCall $call): bool
    {
        return $call->user_id === $user->id || $user->can('crm-department.edit');
    }

    private function clientAccessible(User $user, CrmCall $call): bool
    {
        return $this->resolver->canAccessAttached(
            $user,
            $call->client_user_id,
            $call->related,
        );
    }
}
