<?php

namespace App\Services\Crm;

use App\Enums\Crm\ClientLifecycleStatus;
use App\Models\CrmClientProfile;
use App\Models\CrmClientStatusChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Единственная точка смены жизненного статуса клиента.
 *
 * Профиль и журнал пишутся в одной транзакции: статус без записи «кто и почему»
 * через месяц никому ничего не объясняет.
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
     * История смен статусов клиента для карточки.
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
                'from' => $change->from_value === null
                    ? null
                    : ClientLifecycleStatus::tryFrom($change->from_value)?->label(),
                'to' => ClientLifecycleStatus::tryFrom($change->to_value)?->label() ?? $change->to_value,
                // user_id обнуляется при удалении сотрудника — журнал переживает автора.
                // @phpstan-ignore-next-line nullsafe.neverNull
                'author' => $change->author?->name ?? 'Сотрудник удалён',
                'reason' => $change->reason,
                'created_at' => $change->created_at?->format('d.m.Y H:i'),
            ])
            ->all();
    }
}
