<?php

namespace App\Services\Crm\Mail;

use App\Enums\UserKind;
use App\Models\Company;
use App\Models\CrmClientProfile;
use App\Models\User;

/**
 * Чей это адрес.
 *
 * Отдельной адресной книги в проекте нет — и заводить её заново незачем:
 * прошлый заход показал, что контакты по должностям никто заполнять не станет.
 * Адреса берутся оттуда, где они и так есть: аккаунт партнёра, карточка
 * контрагента и заметки о контактных лицах в анкете CRM.
 *
 * Нужно ровно для одного: письмо, отправленное бухгалтеру партнёра на его
 * личный ящик, должно попасть в ленту этого партнёра, а не повиснуть письмом
 * «в никуда». Совпадение требуется точное — адрес в адрес: догадки здесь
 * означали бы чужую переписку в чужой карточке.
 */
class PartnerAddressBook
{
    /** @var array<string, int|null> */
    private array $cache = [];

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

    private function lookup(string $address): ?int
    {
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

        return $this->fromProfileNotes($address);
    }

    /**
     * Контактные лица из анкеты: там свободный текст вида
     * «Афонина Мария, buh@romashka.ru, +7…».
     *
     * Совпадение проверяется по вхождению адреса целиком, а дальше — точной
     * сверкой найденных в тексте адресов: LIKE-совпадения мало, иначе
     * `buh@romashka.ru` нашёлся бы внутри `sbuh@romashka.ru`.
     */
    private function fromProfileNotes(string $address): ?int
    {
        $fields = ['accountant_contact', 'owner_contact', 'decision_maker_contact'];

        $profiles = CrmClientProfile::query()
            ->where(function ($query) use ($fields, $address) {
                foreach ($fields as $field) {
                    $query->orWhere($field, 'like', '%'.$address.'%');
                }
            })
            ->get(['user_id', ...$fields]);

        foreach ($profiles as $profile) {
            foreach ($fields as $field) {
                if ($this->containsAddress((string) $profile->{$field}, $address)) {
                    return (int) $profile->user_id;
                }
            }
        }

        return null;
    }

    private function containsAddress(string $text, string $address): bool
    {
        if ($text === '') {
            return false;
        }

        preg_match_all('/[\w.+-]+@[\w-]+\.[\w.-]+/u', $text, $matches);

        foreach ($matches[0] ?? [] as $found) {
            if (mb_strtolower(rtrim($found, '.,;')) === $address) {
                return true;
            }
        }

        return false;
    }
}
