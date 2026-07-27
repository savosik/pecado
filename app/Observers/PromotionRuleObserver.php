<?php

namespace App\Observers;

use App\Jobs\RecalculatePromotionRuleProductsJob;
use App\Models\PromotionRule;
use App\Services\Promotion\ActivePromotionRuleCache;

/**
 * Держит в согласии с правилом две производные вещи:
 * состав promotion_rule_product и короткий кэш включённых правил.
 *
 * Пересчёт участников идёт фоном: раскрытие категорий с потомками может дать
 * тысячи товаров, ждать этого в запросе админки незачем.
 */
class PromotionRuleObserver
{
    public function __construct(private readonly ActivePromotionRuleCache $cache) {}

    public function saved(PromotionRule $rule): void
    {
        $this->cache->flush();

        // Состав участников зависит только от селекторов и наград
        if ($rule->wasRecentlyCreated || $rule->wasChanged(['conditions', 'rewards', 'deleted_at'])) {
            RecalculatePromotionRuleProductsJob::dispatch($rule->id);
        }
    }

    public function deleted(PromotionRule $rule): void
    {
        $this->cache->flush();
        RecalculatePromotionRuleProductsJob::dispatch($rule->id);
    }

    public function restored(PromotionRule $rule): void
    {
        $this->cache->flush();
        RecalculatePromotionRuleProductsJob::dispatch($rule->id);
    }
}
