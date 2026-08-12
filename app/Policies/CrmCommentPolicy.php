<?php

namespace App\Policies;

use App\Models\CrmComment;
use App\Models\User;
use App\Services\Crm\CrmEntityResolver;

/**
 * Доступ к комментариям CRM.
 *
 * Право `crm-comments.*` даёт саму возможность работать с комментариями, но не отменяет
 * скоуп партнёров: без проверки видимости менеджер, зная ID, читал бы переписку по чужому
 * партнёру в обход `User::visibleInCrm()`.
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
        return $comment->user_id === $user->id || $user->can('crm-department.edit');
    }

    /**
     * Комментарий без партнёра доступен по той сущности, на которой висит: заказ
     * из 1С без user_id — только тем, кто видит весь отдел, а задача без привязки —
     * своим автору и исполнителю.
     */
    private function clientAccessible(User $user, CrmComment $comment): bool
    {
        return $this->resolver->canAccessAttached(
            $user,
            $comment->client_user_id,
            $comment->commentable,
        );
    }
}
