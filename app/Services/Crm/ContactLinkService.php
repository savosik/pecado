<?php

namespace App\Services\Crm;

use App\Enums\ContactRole;
use App\Enums\ContactSource;
use App\Models\Contact;
use App\Models\ContactLink;
use App\Models\User;
use App\Support\Crm\CrmEntityMap;

/**
 * Привязка человека к сущности с ролью.
 *
 * Отдельный сервис, а не пара строк в контроллере: привязка обязана пройти
 * проверку доступа к сущности (привязать человека к чужому контрагенту нельзя)
 * и денормализовать партнёра — иначе выборка «контакты этого партнёра» перестанет
 * собираться одним запросом.
 */
class ContactLinkService
{
    public function __construct(private readonly CrmEntityResolver $resolver) {}

    /**
     * Привязать контакт к сущности.
     *
     * Сущность резолвится от лица актора: чужая даст 404, а не 403.
     */
    public function link(
        User $actor,
        Contact $contact,
        string $entityType,
        int $entityId,
        ContactRole $role,
        ?string $roleNote = null,
        bool $isPrimary = false,
        ContactSource $source = ContactSource::MANUAL,
    ): ContactLink {
        $subject = $this->resolver->resolveForActor($actor, $entityType, $entityId);

        $link = ContactLink::query()->firstOrNew([
            'contact_id' => $contact->getKey(),
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'role' => $role->value,
        ]);

        $link->fill([
            'role_note' => $roleNote,
            'is_primary' => $isPrimary,
            'client_user_id' => CrmEntityMap::clientIdFor($subject),
            'source' => $source,
        ]);

        if (! $link->exists) {
            $link->created_by_user_id = $actor->getKey();
        }

        $link->save();

        // Первая привязка проставляет человеку партнёра: до неё карточка ничья
        // и видна только тому, кто видит всю базу.
        $this->adoptClient($contact, $link);

        // Основной контакт роли один: назначая нового, снимаем отметку с прежнего.
        if ($isPrimary) {
            $this->demoteSiblings($link);
        }

        return $link;
    }

    /**
     * Отвязать. Строка удаляется физически: мягкое удаление здесь навсегда
     * заблокировало бы повторную привязку через уникальный индекс.
     */
    public function unlink(ContactLink $link): void
    {
        $link->delete();
    }

    private function adoptClient(Contact $contact, ContactLink $link): void
    {
        if ($contact->client_user_id !== null || $link->client_user_id === null) {
            return;
        }

        $contact->forceFill(['client_user_id' => $link->client_user_id])->save();
    }

    private function demoteSiblings(ContactLink $link): void
    {
        ContactLink::query()
            ->where('subject_type', $link->subject_type)
            ->where('subject_id', $link->subject_id)
            ->where('role', $link->role->value)
            ->whereKeyNot($link->getKey())
            ->update(['is_primary' => false]);
    }
}
