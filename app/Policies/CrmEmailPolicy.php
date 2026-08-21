<?php

namespace App\Policies;

use App\Models\CrmEmail;
use App\Models\User;
use App\Services\Crm\CrmEntityResolver;

/**
 * Доступ к письмам CRM.
 *
 * Читать письмо может любой, кому виден его партнёр: переписка с партнёром — это история
 * работы с ним, а не личное дело автора. Здесь граница проходит иначе, чем у задач:
 * задача — поручение конкретному человеку, письмо — факт из жизни партнёра.
 *
 * Править и отправлять может только автор (и РОП): чужое письмо, ушедшее от твоего
 * имени, — это не совместная работа, а подлог.
 *
 * Суперадмин проходит бесплатно через Gate::before в AppServiceProvider.
 */
class CrmEmailPolicy
{
    public function __construct(private readonly CrmEntityResolver $resolver) {}

    public function viewAny(User $user): bool
    {
        return $user->can('crm-emails.view');
    }

    public function view(User $user, CrmEmail $email): bool
    {
        return $user->can('crm-emails.view') && $this->clientAccessible($user, $email);
    }

    public function create(User $user): bool
    {
        return $user->can('crm-emails.create');
    }

    /**
     * Отправленное письмо неизменяемо: журнал, который можно переписать задним числом,
     * бесполезен как журнал.
     */
    public function update(User $user, CrmEmail $email): bool
    {
        return $user->can('crm-emails.edit')
            && $email->status->isEditable()
            && $this->clientAccessible($user, $email)
            && $this->ownsOrLeads($user, $email);
    }

    public function send(User $user, CrmEmail $email): bool
    {
        return $user->can('crm-emails.create')
            && $email->status->isEditable()
            && $this->clientAccessible($user, $email)
            && $this->ownsOrLeads($user, $email);
    }

    /**
     * Удалять можно только неотправленное. Отправленное письмо остаётся в журнале
     * навсегда — это единственный след того, что партнёру написали.
     */
    public function delete(User $user, CrmEmail $email): bool
    {
        return $user->can('crm-emails.delete')
            && $email->status->isEditable()
            && $this->clientAccessible($user, $email)
            && $this->ownsOrLeads($user, $email);
    }

    /**
     * Своё письмо или письмо системы.
     *
     * Письмо, собранное системой, ничьё: оно приписано персональному менеджеру
     * клиента только затем, чтобы попасть в его папку и получить обратный адрес.
     * Запрещать по нему работать замещающему менеджеру было бы вредно — именно
     * в отсутствие персонального и нужно, чтобы клиенту кто-то ответил.
     */
    private function ownsOrLeads(User $user, CrmEmail $email): bool
    {
        return $email->isSystem()
            || $email->user_id === $user->id
            || $user->can('crm-department.edit');
    }

    private function clientAccessible(User $user, CrmEmail $email): bool
    {
        // Письмо без партнёра (свободный адрес) видит и правит только его автор:
        // скоуп партнёров к нему неприменим.
        if ($email->client_user_id === null) {
            return $this->ownsOrLeads($user, $email);
        }

        return $this->resolver->clientVisible($user, $email->client_user_id);
    }
}
