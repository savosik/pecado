<?php

namespace App\Services\Promotion;

use App\Enums\PromotionRuleMode;
use App\Models\Cart;
use App\Models\Product;
use App\Models\PromotionRule;
use App\Models\Scopes\HiddenScope;
use App\Services\Promotion\DTO\AppliedReward;
use App\Services\Promotion\DTO\PromoContext;
use App\Services\Promotion\DTO\PromotionEvaluation;
use Illuminate\Support\Collection;

/**
 * Промо-позиции корзины как строки.
 *
 * Строки **не хранятся** в `cart_items`: движок вычисляет их на каждый рендер.
 * В базе живёт только то, что вычислить нельзя, — выбор клиента
 * (`cart_promotion_selections`).
 *
 * Форма строки совместима с обычной и уценочной (`CartService::defectItemDetails`),
 * чтобы фронт рисовал её тем же компонентом. Отличий три:
 *
 * - `id` **строковый** (`promo:{rule}:{reward}`). Фронт использует `item.id`
 *   и как React-ключ, и как адрес для `/api/cart/items/{id}`; строковый тип —
 *   самая надёжная защита от того, чтобы виртуальная строка уехала в эндпоинт,
 *   который её удалит или изменит количество;
 * - количество не редактируется — его задаёт правило;
 * - цена не пересчитывается по прайсу: её задаёт награда (0, 0.01, 40 ₽).
 */
class CartPromoLines
{
    /** Префикс виртуального id — по нему строка отличается от настоящей. */
    public const ID_PREFIX = 'promo';

    public function __construct(private readonly PromotionEngine $engine) {}

    /**
     * Промо-строки корзины.
     *
     * @return list<array<string, mixed>>
     */
    public function forCart(Cart $cart): array
    {
        return $this->fromEvaluation(
            $this->engine->evaluate(PromoContext::fromCart($cart, $cart->user)),
        );
    }

    /**
     * Промо-строки по готовому расчёту — для чекаута, который пересчитывает
     * акции сам, внутри транзакции, и второй раз движок дёргать не должен.
     *
     * @return list<array<string, mixed>>
     */
    public function fromEvaluation(PromotionEvaluation $evaluation): array
    {
        // В режиме `info` правило только показывает награду: выдаёт её менеджер,
        // строкой в корзине она не становится
        $rewards = array_values(array_filter(
            $evaluation->applied,
            static fn (AppliedReward $reward) => $reward->ruleMode === PromotionRuleMode::ISSUE,
        ));

        if ($rewards === []) {
            return [];
        }

        $rules = $this->rules($rewards);
        $products = $this->products($rewards, $rules);

        return array_map(
            fn (AppliedReward $reward) => $this->line($reward, $rules, $products),
            $rewards,
        );
    }

    /**
     * Виртуальный id строки. Собран так, чтобы по нему можно было вернуться
     * к награде: правило + порядковый номер награды внутри него.
     */
    public static function id(int $ruleId, int $rewardIndex): string
    {
        return self::ID_PREFIX.':'.$ruleId.':'.$rewardIndex;
    }

    /**
     * Разобрать виртуальный id обратно.
     *
     * @return array{0: int, 1: int}|null null — если это не промо-строка
     */
    public static function parseId(mixed $id): ?array
    {
        if (! is_string($id)) {
            return null;
        }

        $parts = explode(':', $id);

        if (count($parts) !== 3 || $parts[0] !== self::ID_PREFIX) {
            return null;
        }

        if (! ctype_digit($parts[1]) || ! ctype_digit($parts[2])) {
            return null;
        }

        return [(int) $parts[1], (int) $parts[2]];
    }

    /**
     * Это виртуальная промо-строка, а не настоящая позиция корзины?
     */
    public static function isPromoId(mixed $id): bool
    {
        return self::parseId($id) !== null;
    }

    /**
     * @param  Collection<int, PromotionRule>  $rules
     * @param  Collection<int, Product>  $products
     * @return array<string, mixed>
     */
    private function line(AppliedReward $reward, Collection $rules, Collection $products): array
    {
        $product = $products->get($reward->productId);
        $rule = $rules->get($reward->ruleId);
        $total = $reward->total();

        return [
            'id' => self::id($reward->ruleId, $reward->rewardIndex),
            'quantity' => $reward->quantity,
            'product' => [
                'id' => $reward->productId,
                'slug' => $product?->slug,
                'name' => $product->name ?? 'Промо-позиция',
                'sku' => $product?->sku,
                'code' => $product?->code,
                'thumbnail_url' => $product?->getFirstMediaUrl('main', 'thumb') ?: null,
                'main_image_url' => $product?->getFirstMediaUrl('main') ?: null,
                'brand' => null,
                'barcodes' => [],
            ],
            'price' => $reward->price,
            'price_regular' => $reward->price,
            'price_discounted' => $reward->price,
            'item_type' => 'promo',
            'promo_kind' => $reward->promoKind->value,
            'promotion' => [
                'rule_id' => $reward->ruleId,
                'reward_index' => $reward->rewardIndex,
                'name' => $rule?->promotion->name ?? $reward->ruleName,
                'url' => $rule?->promotion?->slug
                    ? route('promotions.show', $rule->promotion->slug)
                    : null,
            ],
            'is_optional' => $reward->optional,
            'is_declined' => $reward->declined,
            'choices' => $this->choices($rule, $reward->rewardIndex, $products),
            // Движок уже урезал количество по остатку (см. PromoStockService),
            // поэтому дошедшая сюда строка выдаваема по определению
            'is_unavailable' => false,
            'available_quantity' => $reward->quantity,
            'preorder_quantity' => 0,
            'max_total' => $reward->quantity,
            'stock_status' => 'ok',
            'total_amount' => $total,
            'total_amount_regular' => $total,
            'total_amount_discounted' => $total,
        ];
    }

    /**
     * Варианты на выбор для награды типа `choice` — иначе null.
     *
     * @param  Collection<int, Product>  $products
     * @return list<array<string, mixed>>|null
     */
    private function choices(?PromotionRule $rule, int $rewardIndex, Collection $products): ?array
    {
        $reward = (array) (array_values((array) ($rule->rewards ?? []))[$rewardIndex] ?? []);

        if (($reward['type'] ?? null) !== PromotionRule::REWARD_TYPE_CHOICE) {
            return null;
        }

        $choices = [];

        foreach ((array) ($reward['choices'] ?? []) as $productId) {
            $product = $products->get((int) $productId);

            if ($product === null) {
                continue;
            }

            $choices[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'thumbnail_url' => $product->getFirstMediaUrl('main', 'thumb') ?: null,
            ];
        }

        return $choices;
    }

    /**
     * @param  list<AppliedReward>  $rewards
     * @return Collection<int, PromotionRule>
     */
    private function rules(array $rewards): Collection
    {
        return PromotionRule::query()
            ->with('promotion:id,name,slug')
            ->whereIn('id', array_unique(array_map(
                static fn (AppliedReward $reward) => $reward->ruleId,
                $rewards,
            )))
            ->get()
            ->keyBy('id');
    }

    /**
     * Товары наград и всех вариантов выбора — одним запросом.
     *
     * @param  list<AppliedReward>  $rewards
     * @param  Collection<int, PromotionRule>  $rules
     * @return Collection<int, Product>
     */
    private function products(array $rewards, Collection $rules): Collection
    {
        $ids = array_map(static fn (AppliedReward $reward) => $reward->productId, $rewards);

        foreach ($rules as $rule) {
            foreach ((array) ($rule->rewards ?? []) as $reward) {
                foreach ((array) (((array) $reward)['choices'] ?? []) as $choice) {
                    $ids[] = (int) $choice;
                }
            }
        }

        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids === []) {
            return collect();
        }

        // Промо-товар может быть скрыт из каталога — подарок от этого не перестаёт
        // быть подарком, поэтому глобальный скоуп снимаем
        return Product::withoutGlobalScope(HiddenScope::class)
            ->with('media')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }
}
