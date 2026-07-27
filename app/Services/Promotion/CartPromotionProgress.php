<?php

namespace App\Services\Promotion;

use App\Models\Cart;
use App\Models\PromotionRule;
use App\Models\User;
use App\Services\Promotion\DTO\AppliedReward;
use App\Services\Promotion\DTO\NearMiss;
use App\Services\Promotion\DTO\PromoContext;

/**
 * Прогресс акций по корзине — то, что видит клиент.
 *
 * Волна 1 работает в режиме показа: промо-позиции не выдаются, поэтому здесь
 * только «доберите на X» и «условия выполнены, позицию добавит менеджер».
 *
 * **Причины несрабатывания (`blocked`) наружу не отдаются никогда** — это
 * решение п. 5 дорожной карты: клиенту не сообщаем ни про остаток на складе,
 * ни про исчерпанный лимит. Их видит только администратор в предпросмотре
 * правила (карточка promo-03).
 */
class CartPromotionProgress
{
    /** Сколько карточек показываем клиенту; остальные прячем за «ещё N». */
    public const MAX_VISIBLE = 3;

    public function __construct(
        private readonly PromotionEngine $engine,
        private readonly PromotionRuleDescriber $describer,
    ) {}

    /**
     * @return array{near_miss: array<int, array<string, mixed>>, achieved: array<int, array<string, mixed>>, max_visible: int}
     */
    public function forCart(Cart $cart, ?User $user = null): array
    {
        $context = PromoContext::fromCart($cart, $user ?? $cart->user);
        $evaluation = $this->engine->evaluate($context);

        $ruleIds = array_values(array_unique(array_merge(
            array_map(fn (NearMiss $miss) => $miss->ruleId, $evaluation->nearMiss),
            array_map(fn (AppliedReward $reward) => $reward->ruleId, $evaluation->applied),
        )));

        $rules = PromotionRule::query()
            ->with('promotion:id,name,slug')
            ->whereIn('id', $ruleIds)
            ->get()
            ->keyBy('id');

        $this->describer->warmUp($rules);

        return [
            'near_miss' => $this->nearMissCards($evaluation->nearMiss, $rules),
            'achieved' => $this->achievedCards($evaluation->applied, $rules),
            'max_visible' => self::MAX_VISIBLE,
        ];
    }

    /**
     * @param  NearMiss[]  $nearMiss
     * @param  \Illuminate\Support\Collection<int, PromotionRule>  $rules
     * @return array<int, array<string, mixed>>
     */
    private function nearMissCards(array $nearMiss, $rules): array
    {
        $cards = [];

        foreach ($nearMiss as $miss) {
            $rule = $rules->get($miss->ruleId);

            if (! $rule) {
                continue;
            }

            $cards[] = [
                'rule_id' => $miss->ruleId,
                'title' => $this->title($rule),
                'message' => $this->describer->nearMissMessage($rule, $miss->remaining(), $miss->aggregate),
                'reward_summary' => $this->describer->rewardSummary($rule),
                'aggregate' => $miss->aggregate,
                'current' => $miss->current,
                'target' => $miss->target,
                'remaining' => $miss->remaining(),
                'current_label' => $this->describer->formatAggregate($miss->current, $miss->aggregate),
                'target_label' => $this->describer->formatAggregate($miss->target, $miss->aggregate),
                'remaining_label' => $this->describer->formatAggregate($miss->remaining(), $miss->aggregate),
                'progress' => $miss->progress(),
                'promotion_url' => $rule->promotion?->slug ? route('promotions.show', $rule->promotion->slug) : null,
            ];
        }

        // Ближе к порогу — выше в списке
        usort($cards, fn (array $left, array $right) => $right['progress'] <=> $left['progress']);

        return $cards;
    }

    /**
     * @param  AppliedReward[]  $applied
     * @param  \Illuminate\Support\Collection<int, PromotionRule>  $rules
     * @return array<int, array<string, mixed>>
     */
    private function achievedCards(array $applied, $rules): array
    {
        $cards = [];

        foreach ($applied as $reward) {
            // Одна карточка на правило, даже если наград в нём несколько
            if (isset($cards[$reward->ruleId])) {
                continue;
            }

            $rule = $rules->get($reward->ruleId);

            if (! $rule) {
                continue;
            }

            $cards[$reward->ruleId] = [
                'rule_id' => $reward->ruleId,
                'title' => $this->title($rule),
                'message' => $this->describer->achievedMessage($rule),
                'reward_summary' => $this->describer->rewardSummary($rule),
                // Волна 1 ничего не выдаёт: показываем честно, что позицию добавит менеджер
                'issued' => $reward->isIssuable(),
                'promotion_url' => $rule->promotion?->slug ? route('promotions.show', $rule->promotion->slug) : null,
            ];
        }

        return array_values($cards);
    }

    /**
     * Заголовок карточки: название акции-лендинга, иначе название правила.
     */
    private function title(PromotionRule $rule): string
    {
        return $rule->promotion?->name ?: $rule->name;
    }
}
