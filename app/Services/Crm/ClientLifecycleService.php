<?php

namespace App\Services\Crm;

use App\Enums\Crm\ClientLifecycleStatus;
use App\Enums\UserKind;
use App\Models\CrmClientProfile;
use App\Models\CrmClientStatusChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Единственная точка смены статусов партнёра: жизненного и типа аккаунта.
 *
 * Оба журналируются в `crm_client_status_changes` в одной транзакции со сменой:
 * статус без записи «кто и почему» через месяц никому ничего не объясняет.
 */
class ClientLifecycleService
{
    public function __construct(private readonly ClientProfileService $profiles) {}

    /**
     * @return CrmClientProfile профиль после смены статуса
     */
    public function change(
        User $client,
        ClientLifecycleStatus $to,
        User $actor,
        ?string $reason = null,
    ): CrmClientProfile {
        return DB::transaction(function () use ($client, $to, $actor, $reason): CrmClientProfile {
            $profile = $this->profiles->forClient($client);
            $from = $profile->exists ? $profile->lifecycle_status : null;

            if ($from === $to) {
                return $profile;
            }

            $profile->client()->associate($client);
            $profile->lifecycle_status = $to;
            $profile->lifecycle_changed_at = now();
            $profile->lifecycle_changed_by = $actor->getKey();

            // Подсказка отработала (её приняли или пошли своим путём) — снимаем,
            // иначе она висела бы бейджем поверх уже принятого решения.
            $profile->lifecycle_hint = null;
            $profile->lifecycle_hint_reason = null;
            $profile->lifecycle_hint_at = null;

            $profile->save();

            CrmClientStatusChange::create([
                'client_user_id' => $client->getKey(),
                'field' => CrmClientStatusChange::FIELD_LIFECYCLE,
                'from_value' => $from?->value,
                'to_value' => $to->value,
                'user_id' => $actor->getKey(),
                'reason' => $reason,
            ]);

            return $profile;
        });
    }

    /**
     * Сменить тип аккаунта: партнёр ↔ сотрудник ↔ служебный.
     *
     * Так из базы партнёров убирают то, что 1С прислала партнёром наравне
     * с покупателями: закупщиков, собственных сотрудников, технические учётки.
     * Причина обязательна не по формату, а по смыслу — через полгода «почему
     * этого нет в базе» спросят обязательно.
     *
     * Аккаунт не удаляется и не блокируется: он просто перестаёт быть партнёром
     * для CRM (User::scopeClients), а заказы, документы и вход в кабинет
     * остаются как были.
     */
    public function changeKind(User $client, UserKind $to, User $actor, ?string $reason = null): User
    {
        return DB::transaction(function () use ($client, $to, $actor, $reason): User {
            $from = $client->user_kind;

            if ($from === $to) {
                return $client;
            }

            $client->user_kind = $to;
            $client->save();

            CrmClientStatusChange::create([
                'client_user_id' => $client->getKey(),
                'field' => CrmClientStatusChange::FIELD_KIND,
                'from_value' => $from->value,
                'to_value' => $to->value,
                'user_id' => $actor->getKey(),
                'reason' => $reason,
            ]);

            return $client;
        });
    }

    /**
     * Включить или выключить страховой запас (users.stock_buffer_enabled).
     *
     * Только руками менеджера: автопроставления по анкете нет намеренно —
     * business_type в CRM-профиле может быть неточным (решение заказчика,
     * buf-02). Смена журналируется: включение меняет остатки, которые клиент
     * видит на витрине.
     */
    public function changeStockBuffer(User $client, bool $enabled, User $actor): User
    {
        return DB::transaction(function () use ($client, $enabled, $actor): User {
            $from = (bool) $client->stock_buffer_enabled;

            if ($from === $enabled) {
                return $client;
            }

            $client->stock_buffer_enabled = $enabled;
            $client->save();

            CrmClientStatusChange::create([
                'client_user_id' => $client->getKey(),
                'field' => CrmClientStatusChange::FIELD_STOCK_BUFFER,
                'from_value' => $from ? '1' : '0',
                'to_value' => $enabled ? '1' : '0',
                'user_id' => $actor->getKey(),
            ]);

            return $client;
        });
    }

    /**
     * История смен статусов партнёра для карточки.
     *
     * @return list<array<string, mixed>>
     */
    public function history(User $client, int $limit = 20): array
    {
        return CrmClientStatusChange::query()
            ->where('client_user_id', $client->getKey())
            ->with('author:id,name')
            ->latest('id')
            ->take($limit)
            ->get()
            ->map(fn (CrmClientStatusChange $change): array => [
                'id' => $change->id,
                'field' => $change->field,
                'field_label' => $change->field === CrmClientStatusChange::FIELD_KIND
                    ? 'Тип аккаунта'
                    : 'Жизненный статус',
                'from' => $change->from_value === null
                    ? null
                    : $this->valueLabel($change->field, $change->from_value),
                'to' => $this->valueLabel($change->field, $change->to_value),
                // user_id обнуляется при удалении сотрудника — журнал переживает автора.
                // @phpstan-ignore-next-line nullsafe.neverNull
                'author' => $change->author?->name ?? 'Сотрудник удалён',
                'reason' => $change->reason,
                'created_at' => $change->created_at?->format('d.m.Y H:i'),
            ])
            ->all();
    }

    /**
     * Человекочитаемое значение записи журнала — своё перечисление на каждое поле.
     *
     * Неизвестное значение отдаётся как есть: журнал переживает переименование
     * вариантов, и подставить «—» вместо исторической записи было бы враньём.
     */
    private function valueLabel(string $field, string $value): string
    {
        return match ($field) {
            CrmClientStatusChange::FIELD_KIND => UserKind::tryFrom($value)?->label() ?? $value,
            default => ClientLifecycleStatus::tryFrom($value)?->label() ?? $value,
        };
    }
}
