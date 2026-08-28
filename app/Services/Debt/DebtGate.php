<?php

namespace App\Services\Debt;

use App\Enums\DebtLevel;
use App\Exceptions\DebtRestrictionException;
use App\Models\Cart;
use App\Models\Company;
use App\Models\DebtPause;
use App\Models\DebtState;
use App\Models\User;
use App\Support\Debt\DebtControl;

/**
 * Гейт чекаута по лестнице долга (карточка debt-04).
 *
 * Читает только боевые строки `debt_states`; действующая разблокировка
 * снимает гейт целиком. Ступени:
 *
 *  - no_preorders (партнёр) — предзаказы в корзине отклоняются;
 *  - no_orders (контрагент) — любые заказы от этого контрагента;
 *  - hold (партнёр) — любые заказы партнёра.
 *
 * Гейт не срабатывает без письма предыдущей ступени по построению: ступень
 * ужесточается не больше чем на шаг за ночь, и каждый шаг порождает письмо.
 */
class DebtGate
{
    /**
     * @throws DebtRestrictionException
     */
    public function check(User $user, Company $company, Cart $cart): void
    {
        if (! DebtControl::live(DebtControl::ACTION_GATE)) {
            return;
        }

        $partner = DebtState::query()->partners()->live()->where('user_id', $user->getKey())->first();

        if ($partner === null || ! $partner->level->blocksPreorders()) {
            return;
        }

        if ($this->paused($user, $company)) {
            return;
        }

        $contractor = DebtState::query()->live()
            ->where('user_id', $user->getKey())
            ->where('company_id', $company->getKey())
            ->first();

        $money = number_format((float) $partner->overdue_amount, 0, ',', ' ');

        if ($partner->level === DebtLevel::HOLD) {
            throw new DebtRestrictionException(
                DebtLevel::HOLD,
                (float) $partner->overdue_amount,
                $company->name,
                true,
                sprintf('Оформление заказов приостановлено: просроченная задолженность %s ₽ не погашена. Ограничение снимется автоматически в день поступления оплаты.', $money),
            );
        }

        if ($contractor !== null && $contractor->level->blocksOrders()) {
            throw new DebtRestrictionException(
                $contractor->level,
                (float) $contractor->overdue_amount,
                $company->name,
                false,
                sprintf(
                    'Оформление заказов от «%s» приостановлено: просроченная задолженность %s ₽ не погашена. Выберите другого контрагента или погасите просрочку — ограничение снимется в день оплаты.',
                    $company->name,
                    number_format((float) $contractor->overdue_amount, 0, ',', ' '),
                ),
            );
        }

        if ($this->hasPreorders($cart)) {
            throw new DebtRestrictionException(
                DebtLevel::NO_PREORDERS,
                (float) $partner->overdue_amount,
                $company->name,
                false,
                sprintf('Предзаказы приостановлены до погашения просрочки %s ₽. Уберите позиции предзаказа из корзины — обычные заказы оформляются как прежде.', $money),
            );
        }
    }

    private function paused(User $user, Company $company): bool
    {
        return DebtPause::query()
            ->active()
            ->where('user_id', $user->getKey())
            ->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $company->getKey()))
            ->exists();
    }

    private function hasPreorders(Cart $cart): bool
    {
        return $cart->items->contains(fn ($item): bool => $item->item_type === 'preorder');
    }
}
