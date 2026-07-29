<?php

namespace App\Services\Promotion;

use App\Models\Cart;
use App\Models\Product;
use App\Models\PromotionRule;
use App\Models\Scopes\HiddenScope;
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
 * Награды уходят наружу **структурой, а не строкой**: название, картинка,
 * посчитанное количество и «бесплатно». Описание правила из админки клиенту
 * не годится — «× 1 за 0 ₽ (не более 20 раз)» он читает как ребус и, главное,
 * не видит там числа, которое ему причитается.
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

        $products = $this->rewardProducts($evaluation, $rules);

        return [
            'near_miss' => $this->nearMissCards($evaluation->nearMiss, $rules, $products),
            'achieved' => $this->achievedCards($evaluation->applied, $rules, $products),
            'max_visible' => self::MAX_VISIBLE,
        ];
    }

    /**
     * @param  NearMiss[]  $nearMiss
     * @param  \Illuminate\Support\Collection<int, PromotionRule>  $rules
     * @param  \Illuminate\Support\Collection<int, Product>  $products
     * @return array<int, array<string, mixed>>
     */
    private function nearMissCards(array $nearMiss, $rules, $products): array
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
                'message' => $this->describer->nearMissMessage($miss->remaining(), $miss->aggregate),
                // Количество ещё неизвестно — порог не взят, показываем только «что»
                'rewards' => $this->plannedRewards($rule, $products),
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
     * @param  \Illuminate\Support\Collection<int, Product>  $products
     * @return array<int, array<string, mixed>>
     */
    private function achievedCards(array $applied, $rules, $products): array
    {
        $cards = [];

        foreach ($applied as $reward) {
            $rule = $rules->get($reward->ruleId);

            if (! $rule) {
                continue;
            }

            // Одна карточка на правило: несколько наград складываются в её список
            $cards[$reward->ruleId] ??= [
                'rule_id' => $reward->ruleId,
                'title' => $this->title($rule),
                'message' => $this->describer->achievedMessage($rule),
                'rewards' => [],
                // Волна 1 ничего не выдаёт: показываем честно, что позицию добавит менеджер
                'issued' => $reward->isIssuable(),
                'promotion_url' => $rule->promotion?->slug ? route('promotions.show', $rule->promotion->slug) : null,
            ];

            if ($reward->declined) {
                continue;
            }

            $cards[$reward->ruleId]['rewards'][] = $this->rewardCard(
                $products->get($reward->productId),
                $reward->productId,
                $reward->price,
                $reward->quantity,
            );
        }

        return array_values($cards);
    }

    /**
     * Что клиент получит, когда доберёт порог. Количество не считаем — оно
     * зависит от того, чем именно он доберёт.
     *
     * @param  \Illuminate\Support\Collection<int, Product>  $products
     * @return array<int, array<string, mixed>>
     */
    private function plannedRewards(PromotionRule $rule, $products): array
    {
        $cards = [];

        foreach ((array) ($rule->rewards ?? []) as $reward) {
            $reward = (array) $reward;
            $price = (float) ($reward['price'] ?? 0);

            $ids = ! empty($reward['product_id'])
                ? [(int) $reward['product_id']]
                : array_map('intval', (array) ($reward['choices'] ?? []));

            foreach (array_filter($ids) as $productId) {
                $cards[] = $this->rewardCard($products->get($productId), $productId, $price, null);
            }
        }

        return $cards;
    }

    /**
     * @return array<string, mixed>
     */
    private function rewardCard(?Product $product, int $productId, float $price, ?int $quantity): array
    {
        return [
            'product_id' => $productId,
            'name' => $product->name ?? 'Промо-позиция',
            'url' => $product?->slug ? route('products.show', $product->slug) : null,
            'thumbnail_url' => $product?->getFirstMediaUrl('main', 'thumb') ?: null,
            'quantity' => $quantity,
            'price' => $price,
            'price_label' => $this->describer->promoPriceLabel($price),
            'is_gift' => $price <= 0,
        ];
    }

    /**
     * Товары всех наград — одним запросом с медиа.
     *
     * @param  \Illuminate\Support\Collection<int, PromotionRule>  $rules
     * @return \Illuminate\Support\Collection<int, Product>
     */
    private function rewardProducts(\App\Services\Promotion\DTO\PromotionEvaluation $evaluation, $rules)
    {
        $ids = array_map(fn (AppliedReward $reward) => $reward->productId, $evaluation->applied);

        foreach ($rules as $rule) {
            foreach ((array) ($rule->rewards ?? []) as $reward) {
                $reward = (array) $reward;

                if (! empty($reward['product_id'])) {
                    $ids[] = (int) $reward['product_id'];
                }

                foreach ((array) ($reward['choices'] ?? []) as $choice) {
                    $ids[] = (int) $choice;
                }
            }
        }

        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids === []) {
            return collect();
        }

        return Product::withoutGlobalScope(HiddenScope::class)
            ->with('media')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

    /**
     * Заголовок карточки: название акции-лендинга, иначе название правила.
     */
    private function title(PromotionRule $rule): string
    {
        return $rule->promotion?->name ?: $rule->name;
    }
}
