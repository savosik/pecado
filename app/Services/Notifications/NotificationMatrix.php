<?php

namespace App\Services\Notifications;

use App\Enums\ContactRole;
use App\Models\Contact;
use App\Models\NotificationSuppression;
use App\Models\User;
use App\Support\Notifications\Destination;

/**
 * Матрица уведомлений партнёра для экрана.
 *
 * Один и тот же набор данных отдаётся и менеджеру в CRM, и клиенту в кабинете —
 * разница только в том, какие типы видно. Два разных представления одной
 * настройки развели бы интерфейс и отправку, а это ровно тот класс ошибок,
 * из-за которого подсистему переделывают третий раз.
 */
class NotificationMatrix
{
    public function __construct(
        private readonly NotificationCatalog $catalog,
        private readonly NotificationSettings $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forManager(User $partner): array
    {
        return $this->build($partner, array_keys($this->catalog->all()));
    }

    /**
     * @return array<string, mixed>
     */
    public function forClient(User $partner): array
    {
        return $this->build($partner, $this->catalog->clientVisibleKeys());
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function build(User $partner, array $keys): array
    {
        $rows = [];

        foreach ($keys as $key) {
            $effective = $this->settings->effective((int) $partner->getKey(), $key);

            $rows[] = [
                'key' => $key,
                'label' => $this->catalog->label($key),
                'family' => $this->catalog->family($key),
                'family_label' => $this->catalog->familyLabel($key),
                'enabled' => $effective['enabled'],
                'destinations' => array_map(
                    fn (Destination $d): array => $d->toArray() + ['label' => $d->label()],
                    $effective['destinations'],
                ),
                'options' => $effective['options'],
                'overridden' => $effective['overridden'],
                'changed_by_client' => $effective['changed_by_client'],
                'client_visible' => $this->catalog->visibleToClient($key),
                // Подтип: о каких именно случаях писать. Объявляется поводом
                // в конфиге, поэтому статусы заказа и типы документов
                // рисуются и сохраняются одинаково.
                'subtype' => $this->catalog->subtype($key),
            ];
        }

        return [
            'rows' => $rows,
            'extras' => $this->extras($partner),
            'roles' => array_map(
                fn (ContactRole $role): array => ['value' => $role->value, 'label' => $role->label()],
                ContactRole::cases(),
            ),
            'has_contacts' => Contact::query()->where('client_user_id', $partner->getKey())->exists(),
        ];
    }

    /**
     * Два семейства, которые не описываются поводами.
     *
     * Клиент должен видеть **всю** почту, которую мы ему шлём, иначе он ищет
     * пропавший канал и звонит менеджеру. Поэтому рассылки и письма менеджера
     * стоят в матрице рядом с уведомлениями, хотя настраиваются иначе.
     *
     * @return list<array<string, mixed>>
     */
    private function extras(User $partner): array
    {
        $email = mb_strtolower(trim((string) $partner->email));

        $marketingOff = $email !== '' && NotificationSuppression::query()
            ->active()
            ->where('email', $email)
            ->whereIn('scope', [NotificationSuppression::SCOPE_ALL, NotificationSuppression::SCOPE_MARKETING])
            ->exists();

        return [
            [
                'key' => 'extra.campaigns',
                'label' => 'Рассылки и акции',
                'family_label' => 'Рассылки',
                'enabled' => ! $marketingOff,
                'hint' => 'Новости, акции и подборки. К заказам и документам отношения не имеют.',
                'toggleable' => true,
            ],
            [
                'key' => 'extra.manager',
                'label' => 'Письма менеджера',
                'family_label' => 'Письма менеджера',
                'enabled' => true,
                'hint' => 'Личная переписка с менеджером приходит всегда: это человек пишет человеку, а не рассылка.',
                'toggleable' => false,
            ],
        ];
    }

    /**
     * Переключить рассылки. Отписка от рекламы — единственное, что здесь
     * настраивается: письма менеджера отключить нельзя, и это не упущение.
     */
    public function setMarketing(User $partner, bool $enabled): void
    {
        $email = mb_strtolower(trim((string) $partner->email));

        if ($email === '') {
            return;
        }

        if ($enabled) {
            NotificationSuppression::query()
                ->where('email', $email)
                ->where('scope', NotificationSuppression::SCOPE_MARKETING)
                ->delete();

            return;
        }

        NotificationSuppression::query()->updateOrCreate(
            ['email' => $email, 'scope' => NotificationSuppression::SCOPE_MARKETING],
            [
                'reason' => NotificationSuppression::REASON_UNSUBSCRIBED,
                'note' => 'Отказ от рассылок в матрице уведомлений',
            ],
        );
    }

    /**
     * Люди партнёра для выбора адресата.
     *
     * @return list<array{id: int, label: string, sublabel: string|null}>
     */
    public function contactOptions(User $partner, string $search = ''): array
    {
        return Contact::query()
            ->where('client_user_id', $partner->getKey())
            ->whereNotNull('email')
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner
                ->where('full_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('full_name')
            ->limit(30)
            ->get(['id', 'full_name', 'email', 'position'])
            ->map(fn (Contact $contact): array => [
                'id' => (int) $contact->getKey(),
                'label' => (string) $contact->full_name,
                'sublabel' => trim((string) $contact->email.($contact->position ? ' · '.$contact->position : '')),
            ])
            ->all();
    }

    /**
     * @return list<int>
     */
    public function contactIdsOf(User $partner): array
    {
        return Contact::query()
            ->where('client_user_id', $partner->getKey())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
