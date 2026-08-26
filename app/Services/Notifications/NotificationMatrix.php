<?php

namespace App\Services\Notifications;

use App\Enums\ContactRole;
use App\Models\Contact;
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
            'roles' => array_map(
                fn (ContactRole $role): array => ['value' => $role->value, 'label' => $role->label()],
                ContactRole::cases(),
            ),
            'has_contacts' => Contact::query()->where('client_user_id', $partner->getKey())->exists(),
        ];
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
