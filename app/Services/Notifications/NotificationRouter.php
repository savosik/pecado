<?php

namespace App\Services\Notifications;

use App\Models\Contact;
use App\Models\NotificationSuppression;
use App\Models\PersonalManager;
use App\Models\User;
use App\Support\Notifications\Destination;
use App\Support\Notifications\Occasion;

/**
 * Кому уйдёт уведомление.
 *
 * Единственная точка ответа на этот вопрос. Раньше на него отвечал движок
 * правил: чтобы узнать, что получает клиент, надо было мысленно прогнать
 * все правила по письму. Здесь ответ читается — это настройка партнёра
 * или умолчание типа.
 */
class NotificationRouter
{
    public function __construct(
        private readonly NotificationCatalog $catalog,
        private readonly NotificationSettings $settings,
    ) {}

    /**
     * Нужен ли этот повод партнёру вообще.
     *
     * Спрашивается **до** сборки письма: письмо, которое никому не уйдёт,
     * незачем и создавать. Именно из таких писем состояла папка «Мимо
     * фильтров», и она сбивала с толку — выглядела как недоработка,
     * хотя была нормой.
     */
    public function wants(Occasion $occasion): bool
    {
        if (! $this->catalog->exists($occasion->key)) {
            return false;
        }

        $effective = $this->settings->effective($occasion->clientUserId, $occasion->key);

        return $effective['enabled'] && $this->passesOptions($occasion, $effective['options']);
    }

    /**
     * Адреса, на которые уйдёт этот повод. Пустой массив — не уходит никому,
     * и это нормальное состояние, а не ошибка.
     *
     * @return list<string>
     */
    public function addressesFor(Occasion $occasion): array
    {
        if (! $this->catalog->exists($occasion->key)) {
            return [];
        }

        $effective = $this->settings->effective($occasion->clientUserId, $occasion->key);

        if (! $effective['enabled']) {
            return [];
        }

        if (! $this->passesOptions($occasion, $effective['options'])) {
            return [];
        }

        $addresses = [];

        foreach ($effective['destinations'] as $destination) {
            foreach ($this->expand($destination, $occasion) as $address) {
                $addresses[] = mb_strtolower(trim($address));
            }
        }

        $addresses = array_values(array_unique(array_filter($addresses)));

        // Стоп-лист — техническая защита от жалоб на спам и bounce.
        // Отписка клиента выражается настройкой «не присылать», а не им.
        return array_values(array_filter(
            $addresses,
            fn (string $address): bool => ! NotificationSuppression::blocks($address, $occasion->key),
        ));
    }

    /**
     * Отбор по подтипу: о каких именно случаях писать.
     *
     * Один механизм на все поводы, у которых подтип объявлен, — статусы заказа
     * и типы документов работают одинаково. Раньше это было частным условием
     * для статусов, и второй такой случай превратил бы конструкцию в набор
     * исключений.
     *
     * @param  array<string, mixed>  $options
     */
    private function passesOptions(Occasion $occasion, array $options): bool
    {
        $subtype = $this->catalog->subtype($occasion->key);

        if ($subtype === null) {
            return true;
        }

        $chosen = (array) ($options['subtypes'] ?? []);

        // Пустой набор — все подтипы. Иначе незаполненная настройка означала бы
        // тишину, и молчание выглядело бы как поломка.
        if ($chosen === []) {
            return true;
        }

        return in_array((string) ($occasion->data[$subtype['field']] ?? ''), $chosen, true);
    }

    /**
     * Раскрыть адресата в конкретные адреса.
     *
     * @return list<string>
     */
    private function expand(Destination $destination, Occasion $occasion): array
    {
        return match ($destination->type) {
            Destination::LOGIN => $this->loginEmail($occasion),
            Destination::MANAGER => $this->managerEmail($occasion),
            Destination::CONTACT_ROLE => $this->roleEmails($destination, $occasion),
            Destination::CONTACT => $this->contactEmail($destination, $occasion),
            Destination::EMAIL => array_filter([$destination->email()]),
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private function loginEmail(Occasion $occasion): array
    {
        if ($occasion->clientUserId === null) {
            return [];
        }

        $email = User::query()->whereKey($occasion->clientUserId)->value('email');

        return filled($email) ? [(string) $email] : [];
    }

    /**
     * @return list<string>
     */
    private function managerEmail(Occasion $occasion): array
    {
        $managerId = $occasion->clientUserId === null
            ? null
            : User::query()->whereKey($occasion->clientUserId)->value('personal_manager_id');

        if ($managerId === null) {
            return [];
        }

        $email = PersonalManager::query()->whereKey($managerId)->value('email');

        return filled($email) ? [(string) $email] : [];
    }

    /**
     * Люди нужной роли.
     *
     * Если повод относится к конкретному контрагенту, роль сужается до него:
     * у партнёра с тремя юрлицами письмо про одно из них не должно уходить
     * бухгалтерам двух остальных. Это то, чего не умел движок правил.
     *
     * @return list<string>
     */
    private function roleEmails(Destination $destination, Occasion $occasion): array
    {
        $role = $destination->role();

        if ($role === null || $occasion->clientUserId === null) {
            return [];
        }

        $query = Contact::query()
            ->deliverable()
            ->where('client_user_id', $occasion->clientUserId)
            ->whereHas('links', function ($links) use ($role, $occasion) {
                $links->where('role', $role->value);

                if ($occasion->companyId !== null) {
                    $links->where(fn ($inner) => $inner
                        ->where('subject_type', \App\Models\Company::class)
                        ->where('subject_id', $occasion->companyId));
                }
            });

        $emails = $query->pluck('email')->filter()->map(fn ($e): string => (string) $e)->values()->all();

        // Контрагент без своих контактов — не повод молчать: берём людей
        // партнёра с этой ролью, как было до сужения.
        if ($emails === [] && $occasion->companyId !== null) {
            return Contact::query()
                ->deliverable()
                ->where('client_user_id', $occasion->clientUserId)
                ->whereHas('links', fn ($links) => $links->where('role', $role->value))
                ->pluck('email')
                ->filter()
                ->map(fn ($e): string => (string) $e)
                ->values()
                ->all();
        }

        return $emails;
    }

    /**
     * @return list<string>
     */
    private function contactEmail(Destination $destination, Occasion $occasion): array
    {
        $id = $destination->contactId();

        if ($id === null) {
            return [];
        }

        $contact = Contact::query()
            ->deliverable()
            ->whereKey($id)
            ->when($occasion->clientUserId !== null, fn ($q) => $q->where('client_user_id', $occasion->clientUserId))
            ->first();

        return $contact !== null && filled($contact->email) ? [(string) $contact->email] : [];
    }
}
