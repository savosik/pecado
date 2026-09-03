<?php

namespace App\Services\Order;

use App\Models\OrderReserveOverride;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Политика режима «Заказы в резерве» (res-05): кому доступен резерв и на какой срок.
 *
 * Эффективное участие = глобальный рубильник ∧ флаг 1С (users.reserve_allowed,
 * реплика их реквизита) ∧ не отключено точечно на сайте (order_reserve_overrides).
 * Сайт сужает охват, но не расширяет: без флага 1С резерв недоступен, что бы
 * ни было в отклонениях.
 */
class ReservePolicy
{
    /** Глобальный рубильник режима (тихая выкатка / аварийное гашение). */
    public function enabled(): bool
    {
        return (bool) config('order_reserve.enabled');
    }

    /**
     * Доступен ли резерв партнёру — гейт radio в чекауте и всех действий резерва.
     */
    public function availableFor(User $user): bool
    {
        if (! $this->enabled() || ! $user->reserve_allowed) {
            return false;
        }

        return ! (bool) $this->overrideFor($user)?->disabled;
    }

    /**
     * Срок резерва для партнёра, часов: индивидуальное отклонение либо умолчание.
     */
    public function hoursFor(User $user): int
    {
        return $this->overrideFor($user)?->hours ?? (int) config('order_reserve.hours');
    }

    /**
     * Запрашиваемый срок удержания для нового резервного заказа.
     *
     * Это срок, который сайт отправит в reserved_until; фактический может быть
     * короче — 1С урезает до своего предела удержания и возвращает фактический
     * срок ответным order.updated.
     */
    public function requestedReservedUntil(User $user): CarbonImmutable
    {
        return CarbonImmutable::now()->addHours($this->hoursFor($user));
    }

    private function overrideFor(User $user): ?OrderReserveOverride
    {
        return OrderReserveOverride::query()->where('user_id', $user->id)->first();
    }
}
