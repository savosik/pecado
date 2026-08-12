<?php

namespace App\Policies;

use App\Models\CrmTask;
use App\Models\User;

/**
 * Доступ к задачам CRM.
 *
 * Доступ к конкретной задаче даёт участие в ней: автор, исполнитель или РОП. Проверки
 * скоупа партнёров здесь намеренно нет — она стоит на входе, при создании и перепривязке
 * (`CrmEntityResolver::resolveForActor()` отдаёт 404 на чужого партнёра). Если бы скоуп
 * проверялся ещё и на чтении, задача, поручённая РОПом по партнёру соседнего менеджера,
 * стала бы невидимой собственному исполнителю — то есть поручение просто не сработало бы.
 *
 * Суперадмин проходит бесплатно через Gate::before в AppServiceProvider.
 */
class CrmTaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('crm-tasks.view');
    }

    public function view(User $user, CrmTask $task): bool
    {
        return $user->can('crm-tasks.view') && $this->involvedOrLeads($user, $task);
    }

    public function create(User $user): bool
    {
        return $user->can('crm-tasks.create');
    }

    /**
     * Править может автор (это его поручение) и исполнитель — ему нужно закрыть задачу
     * и уточнить срок. Переназначение на третьего разрешено только автору и РОПу:
     * иначе задачу можно было бы молча спихнуть коллеге.
     */
    public function update(User $user, CrmTask $task): bool
    {
        return $user->can('crm-tasks.edit') && $this->involvedOrLeads($user, $task);
    }

    public function reassign(User $user, CrmTask $task): bool
    {
        return $this->update($user, $task)
            && ($task->author_id === $user->id || $user->can('crm-department.edit'));
    }

    public function delete(User $user, CrmTask $task): bool
    {
        return $user->can('crm-tasks.delete')
            && ($task->author_id === $user->id || $user->can('crm-department.edit'));
    }

    /**
     * Задача видна её участникам; РОП видит задачи всего отдела.
     *
     * Задача коллеги по своему партнёру намеренно не видна рядовому менеджеру: партнёр
     * общий по скоупу только у РОПа, а поручения между сотрудниками — не публичная лента.
     */
    private function involvedOrLeads(User $user, CrmTask $task): bool
    {
        return $task->author_id === $user->id
            || $task->assignee_id === $user->id
            || $user->can('crm-department.edit');
    }
}
