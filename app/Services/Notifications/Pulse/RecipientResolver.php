<?php

namespace App\Services\Notifications\Pulse;

use App\Models\ClientContact;
use App\Models\Company;
use App\Models\NotificationRule;
use App\Models\NotificationRuleRecipient;
use App\Models\User;
use App\Notifications\Pulse\Support\PulseSignal;
use App\Services\Crm\ManagerAbsenceResolver;
use App\Services\Notifications\ClientContactService;

/**
 * Раскрытие получателей правила в конкретные адреса.
 *
 * Здесь же проходит главная проверка безопасности домена: контакт обязан
 * принадлежать партнёру и контрагенту события. Иначе неаккуратно собранное
 * правило отправило бы письмо о финансах «Ромашки» контакту «Одуванчика».
 */
class RecipientResolver
{
    public function __construct(
        private readonly ClientContactService $contacts,
        private readonly ManagerAbsenceResolver $absences,
    ) {}

    /**
     * @return array<int, ResolvedRecipient>
     */
    public function resolve(NotificationRule $rule, PulseSignal $signal): array
    {
        $resolved = [];

        foreach ($rule->recipients as $recipient) {
            foreach ($this->resolveOne($recipient, $rule, $signal) as $item) {
                $resolved[] = $item;
            }
        }

        return $resolved;
    }

    /**
     * @return array<int, ResolvedRecipient>
     */
    private function resolveOne(
        NotificationRuleRecipient $recipient,
        NotificationRule $rule,
        PulseSignal $signal,
    ): array {
        $emails = match ($recipient->kind) {
            NotificationRuleRecipient::KIND_CONTACT => $this->fromContact($recipient, $signal),
            NotificationRuleRecipient::KIND_CONTACT_ROLE => $this->fromRole($recipient, $signal),
            NotificationRuleRecipient::KIND_EMAIL, NotificationRuleRecipient::KIND_SUPPRESS => [$recipient->value],
            NotificationRuleRecipient::KIND_CLIENT_USER => [$this->clientEmail($signal)],
            NotificationRuleRecipient::KIND_COMPANY_EMAIL => [$this->companyEmail($signal)],
            NotificationRuleRecipient::KIND_PERSONAL_MANAGER => [$this->managerEmail($signal)],
            NotificationRuleRecipient::KIND_CONFIG_LIST => $this->fromConfig($recipient),
            default => [],
        };

        $contactId = $recipient->kind === NotificationRuleRecipient::KIND_CONTACT
            ? $recipient->contact_id
            : null;

        $result = [];

        foreach (array_filter($emails, fn ($email) => filled($email)) as $email) {
            $result[] = new ResolvedRecipient(
                email: (string) $email,
                kind: $recipient->kind,
                rule: $rule,
                contactId: $contactId ?? $this->contactIdByEmail($recipient, $email),
                copyType: $recipient->copy_type,
                isFallback: $recipient->is_fallback,
            );
        }

        return $result;
    }

    /**
     * @return array<int, string|null>
     */
    private function fromContact(NotificationRuleRecipient $recipient, PulseSignal $signal): array
    {
        $contact = $recipient->contact;

        if (! $contact instanceof ClientContact) {
            return [];
        }

        // Контакт «Ромашки» не должен получить письмо про «Одуванчик»,
        // даже если правило собрано неаккуратно.
        if (! $contact->belongsToSubject($signal->clientUserId, $signal->companyId)) {
            return [];
        }

        if (! $contact->is_active || $contact->unsubscribed_at !== null) {
            return [];
        }

        return [$contact->email];
    }

    /**
     * @return array<int, string|null>
     */
    private function fromRole(NotificationRuleRecipient $recipient, PulseSignal $signal): array
    {
        if ($signal->clientUserId === null || blank($recipient->value)) {
            return [];
        }

        return $this->contacts
            ->deliverableByRole($signal->clientUserId, $signal->companyId, $recipient->value)
            ->pluck('email')
            ->all();
    }

    private function clientEmail(PulseSignal $signal): ?string
    {
        if ($signal->clientUserId === null) {
            return null;
        }

        return User::query()->whereKey($signal->clientUserId)->value('email');
    }

    private function companyEmail(PulseSignal $signal): ?string
    {
        if ($signal->companyId === null) {
            return null;
        }

        return Company::query()->withoutGlobalScopes()->whereKey($signal->companyId)->value('email');
    }

    /**
     * Персональный менеджер клиента с учётом замещения на время отсутствия.
     *
     * Повторяет цепочку, которая работала в OrderManagerRouting: на время
     * отпуска письмо уходит замещающему — тому же человеку, чьи контакты
     * клиент видит в кабинете. Если у замещающего пустой адрес, письмо
     * возвращается менеджеру: прочитает после выхода, это лучше потери.
     */
    private function managerEmail(PulseSignal $signal): ?string
    {
        if ($signal->clientUserId === null) {
            return null;
        }

        $card = User::query()->with('personalManager')->find($signal->clientUserId)?->personalManager;

        if ($card === null) {
            return null;
        }

        return $this->absences->effectiveManager($card)->email ?: $card->email;
    }

    /**
     * Адреса из настроек — только по ключам из белого списка.
     *
     * Без белого списка правило смогло бы прочитать любой ключ конфигурации
     * приложения, включая не предназначенный для рассылки.
     *
     * @return array<int, string>
     */
    private function fromConfig(NotificationRuleRecipient $recipient): array
    {
        $allowed = array_keys((array) config('notification_pulse.config_recipient_lists', []));

        if (! in_array((string) $recipient->value, $allowed, true)) {
            return [];
        }

        return array_values(array_filter((array) config($recipient->value, [])));
    }

    /**
     * Найти карточку контакта по адресу — чтобы журнал знал, кому писали,
     * даже когда адрес пришёл из роли или списка.
     */
    private function contactIdByEmail(NotificationRuleRecipient $recipient, string $email): ?int
    {
        if ($recipient->kind !== NotificationRuleRecipient::KIND_CONTACT_ROLE) {
            return null;
        }

        return ClientContact::query()
            ->where('email', mb_strtolower(trim($email)))
            ->value('id');
    }
}
