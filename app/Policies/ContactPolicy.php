<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;
use App\Services\Crm\CrmEntityResolver;

/**
 * Доступ к карточкам людей.
 *
 * Граница проходит по партнёру: кто видит партнёра — видит его контакты.
 * Отдельного скоупа у справочника нет намеренно, иначе появилось бы второе
 * представление о том, чей это клиент, и рано или поздно они разошлись бы.
 *
 * Человек без партнёра (водитель перевозчика, наш подрядчик) доступен только
 * тому, кто видит всю базу: иначе он всплывал бы у каждого менеджера.
 *
 * Суперадмин проходит бесплатно через Gate::before в AppServiceProvider.
 */
class ContactPolicy
{
    public function __construct(private readonly CrmEntityResolver $resolver) {}

    public function viewAny(User $user): bool
    {
        return $user->can('crm-contacts.view');
    }

    public function view(User $user, Contact $contact): bool
    {
        return $user->can('crm-contacts.view') && $this->accessible($user, $contact);
    }

    public function create(User $user): bool
    {
        return $user->can('crm-contacts.create');
    }

    public function update(User $user, Contact $contact): bool
    {
        return $user->can('crm-contacts.edit') && $this->accessible($user, $contact);
    }

    /**
     * Удаление мягкое, но всё равно под отдельным правом: за карточкой тянутся
     * письма и звонки, и стирать её должен не всякий, кто может поправить телефон.
     */
    public function delete(User $user, Contact $contact): bool
    {
        return $user->can('crm-contacts.delete') && $this->accessible($user, $contact);
    }

    private function accessible(User $user, Contact $contact): bool
    {
        if ($contact->client_user_id === null) {
            return $user->can('crm-clients-all.view')
                || (int) $contact->created_by_user_id === (int) $user->getKey();
        }

        return $this->resolver->clientVisible($user, $contact->client_user_id);
    }
}
