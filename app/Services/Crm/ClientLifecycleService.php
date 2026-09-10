<?php

namespace App\Services\Crm;

use App\Contracts\Cart\CartServiceInterface;
use App\Enums\Crm\ClientLifecycleStatus;
use App\Enums\UserKind;
use App\Models\CrmClientProfile;
use App\Models\CrmClientStatusChange;
use App\Models\PersonalManager;
use App\Models\ProductExport;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Единственная точка смены статусов партнёра: жизненного, типа аккаунта,
 * закрепления за менеджером.
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
        return $this->apply($client, $to, $actor, $reason);
    }

    /**
     * Смена без сотрудника — её сделала система (ночная команда возвращения).
     *
     * В журнале у такой записи `user_id = NULL`, и карточка показывает её как
     * системную. Причина обязательна: смена без человека и без объяснения —
     * ровно то, из-за чего полю статуса перестают верить.
     */
    public function changeBySystem(
        User $client,
        ClientLifecycleStatus $to,
        string $reason,
    ): CrmClientProfile {
        return $this->apply($client, $to, null, $reason);
    }

    private function apply(
        User $client,
        ClientLifecycleStatus $to,
        ?User $actor,
        ?string $reason,
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
            $profile->lifecycle_changed_by = $actor?->getKey();

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
                'user_id' => $actor?->getKey(),
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
     * Закрепить партнёра за менеджером или снять закрепление (users.personal_manager_id).
     *
     * Только руками РОПа: с v16.10.0 1С поле `manager` не задаёт, поэтому эта
     * запись — единственный след того, кто и когда передал партнёра. `null` —
     * партнёр остаётся в базе отдела без менеджера (лид), из CRM он не пропадает.
     */
    public function changeManager(User $client, ?PersonalManager $to, User $actor, ?string $reason = null): User
    {
        return DB::transaction(function () use ($client, $to, $actor, $reason): User {
            $from = $client->personal_manager_id === null ? null : (int) $client->personal_manager_id;
            $toId = $to?->getKey();

            if ($from === $toId) {
                return $client;
            }

            $client->personal_manager_id = $toId;
            $client->save();

            CrmClientStatusChange::create([
                'client_user_id' => $client->getKey(),
                'field' => CrmClientStatusChange::FIELD_MANAGER,
                'from_value' => $from === null ? null : (string) $from,
                'to_value' => $toId === null ? CrmClientStatusChange::MANAGER_NONE : (string) $toId,
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

            // Адресный сброс кеша выгрузок (buf-05): только этого клиента —
            // его витрина должна увидеть новые остатки, чужие кеши не трогаем.
            ProductExport::query()
                ->where('client_user_id', $client->getKey())
                ->whereNotNull('cached_at')
                ->update(['cached_at' => null]);

            return $client;
        });
    }

    /**
     * Предзаказы: предлагать ли клиенту заказ товара без остатка у поставщика.
     *
     * Единая точка для кабинета (актор — сам клиент) и CRM (актор — менеджер).
     * При выключении из корзин клиента уходят предзаказные строки: иначе
     * чекаут показал бы «остатки изменились» на товар, который клиент
     * только что попросил не предлагать.
     */
    public function changePreorders(User $client, bool $enabled, User $actor): User
    {
        return DB::transaction(function () use ($client, $enabled, $actor): User {
            $from = $client->preordersEnabled();

            if ($from === $enabled) {
                return $client;
            }

            $client->preorders_enabled = $enabled;
            $client->save();

            CrmClientStatusChange::create([
                'client_user_id' => $client->getKey(),
                'field' => CrmClientStatusChange::FIELD_PREORDERS,
                'from_value' => $from ? '1' : '0',
                'to_value' => $enabled ? '1' : '0',
                'user_id' => $actor->getKey(),
            ]);

            if (! $enabled) {
                app(CartServiceInterface::class)->removePreorderItems($client);
            }

            // Выгрузки клиента (остатки/прайс) считались с предзаказным складом —
            // сбрасываем кеш адресно, как и для страхового запаса.
            ProductExport::query()
                ->where('client_user_id', $client->getKey())
                ->whereNotNull('cached_at')
                ->update(['cached_at' => null]);

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
        $changes = CrmClientStatusChange::query()
            ->where('client_user_id', $client->getKey())
            ->with('author:id,name')
            ->latest('id')
            ->take($limit)
            ->get();

        // Имена менеджеров — одним запросом на всю историю, а не по строке:
        // в журнале id карточки, читателю нужна фамилия.
        $managerIds = $changes
            ->where('field', CrmClientStatusChange::FIELD_MANAGER)
            ->flatMap(fn (CrmClientStatusChange $change): array => [$change->from_value, $change->to_value])
            ->filter(fn (?string $value): bool => $value !== null && ctype_digit($value))
            ->map(fn (string $value): int => (int) $value)
            ->unique()
            ->values();
        $managerNames = $managerIds->isEmpty()
            ? []
            : PersonalManager::query()->whereKey($managerIds)->pluck('name', 'id')->all();

        return $changes
            ->map(fn (CrmClientStatusChange $change): array => [
                'id' => $change->id,
                'field' => $change->field,
                'field_label' => match ($change->field) {
                    CrmClientStatusChange::FIELD_KIND => 'Тип аккаунта',
                    CrmClientStatusChange::FIELD_MANAGER => 'Персональный менеджер',
                    default => 'Жизненный статус',
                },
                'from' => $change->from_value === null
                    ? null
                    : $this->valueLabel($change->field, $change->from_value, $managerNames),
                'to' => $this->valueLabel($change->field, $change->to_value, $managerNames),
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
     * Снятые стадии («Непреодолимо», «Закрывается») подписываются по словарю
     * енума — иначе в истории читалось бы сырое `hopeless`. Удалённая карточка
     * менеджера подписывается номером — журнал переживает и её.
     *
     * @param  array<int, string>  $managerNames
     */
    private function valueLabel(string $field, string $value, array $managerNames = []): string
    {
        return match ($field) {
            CrmClientStatusChange::FIELD_KIND => UserKind::tryFrom($value)?->label() ?? $value,
            CrmClientStatusChange::FIELD_MANAGER => $value === CrmClientStatusChange::MANAGER_NONE
                ? 'Не закреплён'
                : ($managerNames[(int) $value] ?? "Менеджер №{$value}"),
            default => ClientLifecycleStatus::tryFrom($value)?->label()
                ?? ClientLifecycleStatus::retiredLabel($value)
                ?? $value,
        };
    }
}
