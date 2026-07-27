<?php

namespace App\Contracts\Promotion;

/**
 * Сколько раз правило акции уже сработало — для лимитов `limits.total`
 * и `limits.per_client_total`.
 *
 * История выдачи появляется в волне 2 вместе со ссылкой `order_items.promotion_rule_id`.
 * До тех пор работает заглушка NoPromoUsageHistory: выдач не было, счётчики нулевые.
 */
interface PromoUsageCounterInterface
{
    /** Сколько раз правило сработало всего. */
    public function totalUsage(int $ruleId): int;

    /** Сколько раз правило сработало у конкретного клиента. */
    public function clientUsage(int $ruleId, ?int $userId): int;
}
