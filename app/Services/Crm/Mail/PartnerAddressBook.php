<?php

namespace App\Services\Crm\Mail;

use App\Enums\UserKind;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;

/**
 * Чей это адрес.
 *
 * Первым спрашиваем справочник людей: это единственный источник, где адрес
 * заведён человеком осознанно и привязан к конкретному лицу. Остальные —
 * производные аккаунта партнёра и реквизитов юрлица.
 *
 * Пустой справочник не ломает ничего: поиск деградирует к прежнему поведению
 * (аккаунт партнёра → почта контрагента), а не молчит.
 *
 * Нужно для двух вещей сразу. Письмо бухгалтеру должно попасть в ленту его
 * партнёра — и в карточку самого бухгалтера. Совпадение требуется точное,
 * адрес в адрес: догадки здесь означали бы чужую переписку в чужой карточке.
 */
class PartnerAddressBook
{
    /** @var array<string, int|null> */
    private array $cache = [];

    /** @var array<string, Contact|null> */
    private array $contactCache = [];

    /**
     * Партнёр, которому принадлежит адрес, или null.
     */
    public function resolve(string $email): ?int
    {
        $address = mb_strtolower(trim($email));

        if ($address === '' || ! str_contains($address, '@')) {
            return null;
        }

        if (array_key_exists($address, $this->cache)) {
            return $this->cache[$address];
        }

        return $this->cache[$address] = $this->lookup($address);
    }

    /**
     * Первый узнанный адрес из списка.
     *
     * @param  array<int, string>  $emails
     */
    public function resolveAny(array $emails): ?int
    {
        foreach ($emails as $email) {
            $clientId = $this->resolve((string) $email);

            if ($clientId !== null) {
                return $clientId;
            }
        }

        return null;
    }

    /**
     * Человек, которому принадлежит адрес.
     *
     * Отдельно от resolve(): письмо подшивается и к партнёру, и к карточке
     * человека, и второе без первого бессмысленно, а первое без второго —
     * ровно то, что было до справочника.
     */
    public function resolveContact(string $email): ?Contact
    {
        $address = mb_strtolower(trim($email));

        if ($address === '' || ! str_contains($address, '@')) {
            return null;
        }

        if (array_key_exists($address, $this->contactCache)) {
            return $this->contactCache[$address];
        }

        return $this->contactCache[$address] = Contact::query()
            ->deliverable()
            ->where('email', $address)
            ->orderByDesc('client_user_id')
            ->first();
    }

    /**
     * @param  array<int, string>  $emails
     */
    public function resolveAnyContact(array $emails): ?Contact
    {
        foreach ($emails as $email) {
            $contact = $this->resolveContact((string) $email);

            if ($contact !== null) {
                return $contact;
            }
        }

        return null;
    }

    private function lookup(string $address): ?int
    {
        // Справочник людей — первым: там адрес заведён осознанно и привязан
        // к конкретному человеку, а не выведен из реквизитов.
        $contact = $this->resolveContact($address);

        if ($contact?->client_user_id !== null) {
            return (int) $contact->client_user_id;
        }

        // Аккаунт партнёра. Сотрудников сюда не пускаем: письмо коллеге —
        // не переписка с клиентом.
        $userId = User::query()
            ->where('user_kind', UserKind::CLIENT)
            ->whereRaw('LOWER(email) = ?', [$address])
            ->value('id');

        if ($userId !== null) {
            return (int) $userId;
        }

        $companyOwner = Company::query()
            ->withoutGlobalScopes()
            ->whereNotNull('user_id')
            ->whereRaw('LOWER(email) = ?', [$address])
            ->value('user_id');

        if ($companyOwner !== null) {
            return (int) $companyOwner;
        }

        return null;
    }
}
