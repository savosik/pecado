<?php

namespace App\Services\Order;

use App\Models\OrderReserveOverride;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Политика режима «Заказы в резерве» (res-05): кому доступен резерв и на какой срок.
 *
 * Эффективное участие = глобальный рубильник ∧ флаг 1С (users.reserve_allowed,
 * реплика их реквизита) ∧ канареечный список (если задан) ∧ не отключено точечно
 * на сайте (order_reserve_overrides). Сайт сужает охват, но не расширяет: без
 * флага 1С резерв недоступен, что бы ни было в отклонениях и канарейках.
 */
class ReservePolicy
{
    public function __construct(private readonly ReserveDeadlineCalculator $deadlines) {}

    /** Глобальный рубильник режима (тихая выкатка / аварийное гашение). */
    public function enabled(): bool
    {
        return (bool) config('order_reserve.enabled');
    }

    /**
     * Канареечный список UUID партнёров (erp_id) на время совместных испытаний.
     *
     * Пустой список — режим работает для всех участников 1С (штатное состояние).
     * Непустой — только для перечисленных: так прогоняются Р-1…Р-6 на боевом
     * контуре, не открывая удержание остатков всем 84 интернет-магазинам разом.
     *
     * Отдельная переменная окружения, а не 82 строки ручных отключений в БД:
     * снимается одним движением, не мусорит в рычаге РОПа (там живут решения
     * по злоупотреблениям) и самой своей пустотой говорит «испытания кончились».
     *
     * @return list<string>
     */
    public function canaryUuids(): array
    {
        $raw = (string) config('order_reserve.canary', '');

        return array_values(array_filter(array_map(
            static fn (string $uuid): string => trim($uuid),
            explode(',', $raw),
        )));
    }

    /**
     * Доступен ли резерв партнёру — гейт radio в чекауте и всех действий резерва.
     */
    public function availableFor(User $user): bool
    {
        if (! $this->enabled() || ! $user->reserve_allowed) {
            return false;
        }

        $canary = $this->canaryUuids();

        if ($canary !== [] && ! in_array((string) $user->erp_id, $canary, true)) {
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
     * срок ответным order.updated. Отсчёт — от начала текущей секунды: `date`
     * заказа (created_at) не раньше неё, поэтому reserved_until − date не выходит
     * за предел 1С и ограничитель их стороны срок не режет.
     *
     * Null — резерв от этого момента не предлагается (новогодние каникулы), см.
     * {@see blockReason()}; вызывающий обязан отказать явно, а не оформить отгрузку.
     */
    public function requestedReservedUntil(User $user, ?CarbonImmutable $at = null): ?CarbonImmutable
    {
        $at = ($at ?? CarbonImmutable::now())->startOfSecond();

        if (! $this->workingDays()) {
            return $at->addHours($this->hoursFor($user));
        }

        return $this->deadlines->deadline($at, $this->hoursFor($user), $this->holdLimitHours());
    }

    /**
     * Почему участнику сейчас нельзя оформить резерв при том, что режим ему доступен.
     * Человеческий текст для чекаута и клиентского API; null — можно.
     */
    public function blockReason(User $user, ?CarbonImmutable $at = null): ?string
    {
        if (! $this->workingDays()) {
            return null;
        }

        $at = ($at ?? CarbonImmutable::now())->startOfSecond();

        return match ($this->deadlines->blockReason($at, $this->hoursFor($user), $this->holdLimitHours())) {
            null => null,
            ReserveDeadlineCalculator::REASON_NEW_YEAR => 'Через новогодние праздники резервы не принимаем — оформите заказ к отгрузке, после праздников резервы снова доступны.',
            default => 'Сейчас резерв оформить нельзя — оформите заказ к отгрузке.',
        };
    }

    /** Срок считается в рабочих днях клиента (res-12), а не календарными часами. */
    public function workingDays(): bool
    {
        return (bool) config('order_reserve.working_days');
    }

    public function holdLimitHours(): int
    {
        return (int) config('order_reserve.hold_limit_hours', 240);
    }

    private function overrideFor(User $user): ?OrderReserveOverride
    {
        return OrderReserveOverride::query()->where('user_id', $user->id)->first();
    }
}
