<?php

namespace App\Services\Promotion;

use App\Contracts\Promotion\PromoUsageCounterInterface;

/**
 * Заглушка счётчика срабатываний для волны 1: выдачи промо-позиций ещё нет,
 * значит и история пустая. Реальный счётчик появится вместе с
 * `order_items.promotion_rule_id` в волне 2.
 */
class NoPromoUsageHistory implements PromoUsageCounterInterface
{
    public function totalUsage(int $ruleId): int
    {
        return 0;
    }

    public function clientUsage(int $ruleId, ?int $userId): int
    {
        return 0;
    }
}
