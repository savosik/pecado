<?php

namespace App\Services\Promotion;

use App\Models\PromotionRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Короткий кэш включённых правил акций.
 *
 * Движок вызывается на каждый рендер корзины, а правил единицы — держать их в Redis
 * дешевле, чем ходить в БД. Инвалидация — обсервером PromotionRuleObserver.
 *
 * Кэшируются правила по флагу `is_active`, а период действия проверяется уже в PHP:
 * иначе правило, стартующее в середине окна TTL, включалось бы с опозданием до минуты.
 */
class ActivePromotionRuleCache
{
    /** Время жизни кэша, секунды. */
    public const TTL = 60;

    public const KEY = 'promo:rules:enabled';

    /**
     * Включённые правила, отсортированные детерминированно:
     * приоритет по убыванию, при равенстве — меньший id.
     *
     * @return Collection<int, PromotionRule>
     */
    public function enabled(): Collection
    {
        /** @var Collection<int, PromotionRule> */
        return Cache::remember(self::KEY, self::TTL, fn () => PromotionRule::query()
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get());
    }

    /**
     * Правила, действующие в указанный момент (по умолчанию — сейчас).
     *
     * @return Collection<int, PromotionRule>
     */
    public function activeAt(?\DateTimeInterface $at = null): Collection
    {
        return $this->enabled()->filter(fn (PromotionRule $rule) => $rule->isActiveAt($at))->values();
    }

    public function flush(): void
    {
        Cache::forget(self::KEY);
    }
}
