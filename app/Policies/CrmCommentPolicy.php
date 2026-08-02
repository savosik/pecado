<?php

namespace App\Policies;

use App\Models\CrmComment;
use App\Models\User;
use App\Services\Crm\CrmEntityResolver;

/**
 * Доступ к комментариям CRM.
 *
 * Право `crm-comments.*` даёт саму возможность работать с комментариями, но не отменяет
 * скоуп клиентов: без проверки видимости менеджер, зная ID, читал бы переписку по чужому
 * клиенту в обход `User::visibleInCrm()`.
 *
 * Суперадмин проходит бесплатно через Gate::before в AppServiceProvider.
 */
class CrmCommentPolicy
{
    public function __construct(private readonly CrmEntityResolver $resolver) {}

    public function viewAny(User $user): bool
    {
        return $user->can('crm-comments.view');
    }

    public function view(User $user, CrmComment $comment): bool
    {
        return $user->can('crm-comments.view') && $this->clientAccessible($user, $comment);
    }

    public function create(User $user): bool
    {
        return $user->can('crm-comments.create');
    }

    /**
     * Править можно своё; РОП — любое в своём отделе (нужно, чтобы убрать чужую ошибку,
     * пока автор в отпуске).
     */
    public function update(User $user, CrmComment $comment): bool
    {
        return $user->can('crm-comments.edit')
            && $this->clientAccessible($user, $comment)
            && $this->ownsOrLeads($user, $comment);
    }

    public function delete(User $user, CrmComment $comment): bool
    {
        return $user->can('crm-comments.delete')
            && $this->clientAccessible($user, $comment)
            && $this->ownsOrLeads($user, $comment);
    }

    private function ownsOrLeads(User $user, CrmComment $comment): bool
    {
        return $comment->user_id === $user->id || $user->can('crm-clients-all.view');
    }

    /**
     * Комментарий без клиента (заказ из 1С без user_id) доступен только тем,
     * кто видит весь отдел — у остальных такой документ вне скоупа.
     */
    private function clientAccessible(User $user, CrmComment $comment): bool
    {
        if ($comment->client_user_id === null) {
            return $user->can('crm-clients-all.view');
        }

        return $this->resolver->clientVisible($user, $comment->client_user_id);
    }
}
