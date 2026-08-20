<?php

namespace App\Services\Notifications\Pulse;

use App\Models\NotificationRule;
use App\Models\User;
use App\Notifications\Pulse\Support\PulseSignal;
use Illuminate\Database\Eloquent\Collection;

/**
 * Подбор правил под сигнал.
 *
 * Область правила сужает выборку до тех, кого событие касается: глобальные
 * правила плюс правила этого партнёра, его юрлица и его персонального
 * менеджера. Порядок — приоритет по возрастанию, при равенстве по id,
 * чтобы разбор был воспроизводимым.
 *
 * @see ConditionEvaluator условия проверяются уже после выборки — в них
 *      участвуют вычисленные поля, которых в запросе нет
 */
class RuleMatcher
{
    public function __construct(private readonly NotificationEventRegistry $registry) {}

    /**
     * @return Collection<int, NotificationRule>
     */
    public function rulesFor(PulseSignal $signal): Collection
    {
        $managerId = $this->managerIdFor($signal->clientUserId);

        return NotificationRule::query()
            ->with('recipients.contact')
            ->where('is_active', true)
            ->whereIn('event_key', $this->registry->matchKeys($signal->eventKey))
            ->where(function ($query) use ($signal, $managerId) {
                $query->where('scope_type', NotificationRule::SCOPE_GLOBAL);

                if ($signal->clientUserId !== null) {
                    $query->orWhere(function ($q) use ($signal) {
                        $q->where('scope_type', NotificationRule::SCOPE_USER)
                            ->where('scope_user_id', $signal->clientUserId);
                    });
                }

                if ($signal->companyId !== null) {
                    $query->orWhere(function ($q) use ($signal) {
                        $q->where('scope_type', NotificationRule::SCOPE_COMPANY)
                            ->where('scope_company_id', $signal->companyId);
                    });
                }

                if ($managerId !== null) {
                    $query->orWhere(function ($q) use ($managerId) {
                        $q->where('scope_type', NotificationRule::SCOPE_MANAGER)
                            ->where('scope_manager_id', $managerId);
                    });
                }
            })
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    private function managerIdFor(?int $clientUserId): ?int
    {
        if ($clientUserId === null) {
            return null;
        }

        return User::query()->whereKey($clientUserId)->value('personal_manager_id');
    }
}
