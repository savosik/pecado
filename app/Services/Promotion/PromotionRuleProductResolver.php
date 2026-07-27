<?php

namespace App\Services\Promotion;

use App\Models\Category;
use App\Models\Product;
use App\Models\Scopes\HiddenScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Раскрывает селекторы правила акции в конкретные товары.
 *
 * Один и тот же код используют материализация участников
 * (RecalculatePromotionRuleProductsJob) и валидатор конфигурации правила,
 * поэтому «участвует в условии» везде означает ровно одно и то же.
 *
 * Скрытые товары (hidden) в выборку попадают: правило настраивается заранее,
 * а видимость на витрине проверяет уже движок.
 */
class PromotionRuleProductResolver
{
    /**
     * Товары, попадающие под селектор.
     *
     * Селектор «вся корзина» (whole_cart) и пустой селектор конкретных товаров
     * не дают — материализовать весь каталог смысла нет.
     *
     * @param  array<string, mixed>  $selector
     * @return int[]
     */
    public function resolveSelector(array $selector): array
    {
        $query = $this->selectorQuery($selector);

        if ($query === null) {
            return [];
        }

        return $query->pluck('products.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Все товары, участвующие в условиях правила.
     *
     * @param  array<string, mixed>  $conditions
     * @return int[]
     */
    public function resolveConditionProducts(array $conditions): array
    {
        $ids = [];

        foreach ($conditions['items'] ?? [] as $item) {
            if (! is_array($item) || ! is_array($item['selector'] ?? null)) {
                continue;
            }

            $ids = array_merge($ids, $this->resolveSelector($item['selector']));
        }

        return array_values(array_unique($ids));
    }

    /**
     * Все товары, которые правило может выдать как награду.
     *
     * @param  array<int, mixed>  $rewards
     * @return int[]
     */
    public function resolveRewardProducts(array $rewards): array
    {
        $ids = [];

        foreach ($rewards as $reward) {
            if (! is_array($reward)) {
                continue;
            }

            if (! empty($reward['product_id'])) {
                $ids[] = (int) $reward['product_id'];
            }

            foreach ($reward['choices'] ?? [] as $choice) {
                if (! empty($choice)) {
                    $ids[] = (int) $choice;
                }
            }
        }

        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        // Отсеиваем несуществующие товары, чтобы не ловить ошибку внешнего ключа
        return Product::withoutGlobalScope(HiddenScope::class)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Попадает ли товар хотя бы под один селектор условий правила.
     *
     * @param  array<string, mixed>  $conditions
     * @param  bool  $wholeCartMatches  Считать ли попаданием селектор «вся корзина»
     */
    public function matchesConditions(int $productId, array $conditions, bool $wholeCartMatches = true): bool
    {
        foreach ($conditions['items'] ?? [] as $item) {
            if (! is_array($item) || ! is_array($item['selector'] ?? null)) {
                continue;
            }

            if (! empty($item['selector']['whole_cart'])) {
                if ($wholeCartMatches) {
                    return true;
                }

                continue;
            }

            $query = $this->selectorQuery($item['selector']);

            if ($query !== null && $query->where('products.id', $productId)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Запрос товаров по селектору или NULL, если конкретных товаров селектор не задаёт.
     *
     * @param  array<string, mixed>  $selector
     * @return Builder<Product>|null
     */
    public function selectorQuery(array $selector): ?Builder
    {
        $products = $this->intList($selector['products'] ?? []);
        $categories = $this->expandCategories(
            $this->intList($selector['categories'] ?? []),
            (bool) ($selector['with_descendants'] ?? false),
        );
        $brands = $this->intList($selector['brands'] ?? []);
        $tags = array_values(array_filter(array_map(
            fn ($tag) => is_string($tag) ? trim($tag) : '',
            (array) ($selector['tags'] ?? []),
        )));
        $erpTypes = array_values(array_filter(array_map(
            fn ($type) => is_string($type) ? $type : '',
            (array) ($selector['erp_promotions'] ?? []),
        )));

        if ($products === [] && $categories === [] && $brands === [] && $tags === [] && $erpTypes === []) {
            return null;
        }

        $morphClass = (new Product)->getMorphClass();
        $locale = app()->getLocale();

        return Product::withoutGlobalScope(HiddenScope::class)
            ->where(function (Builder $query) use ($products, $categories, $brands, $tags, $erpTypes, $morphClass, $locale) {
                if ($products !== []) {
                    $query->orWhereIn('products.id', $products);
                }

                if ($categories !== []) {
                    $query->orWhereIn('products.category_id', $categories);
                }

                if ($brands !== []) {
                    $query->orWhereIn('products.brand_id', $brands);
                }

                if ($tags !== []) {
                    $query->orWhereExists(function ($sub) use ($tags, $morphClass, $locale) {
                        $sub->select(DB::raw(1))
                            ->from('taggables')
                            ->join('tags', 'tags.id', '=', 'taggables.tag_id')
                            ->whereColumn('taggables.taggable_id', 'products.id')
                            ->where('taggables.taggable_type', $morphClass)
                            ->whereIn('tags.name->'.$locale, $tags);
                    });
                }

                if ($erpTypes !== []) {
                    $query->orWhereExists(function ($sub) use ($erpTypes) {
                        $sub->select(DB::raw(1))
                            ->from('erp_promotion_product')
                            ->join('erp_promotions', 'erp_promotions.id', '=', 'erp_promotion_product.erp_promotion_id')
                            ->whereColumn('erp_promotion_product.product_id', 'products.id')
                            ->whereIn('erp_promotions.type', $erpTypes);
                    });
                }
            });
    }

    /**
     * Раскрыть категории вместе с потомками (kalnoy/nestedset).
     *
     * @param  int[]  $categoryIds
     * @return int[]
     */
    private function expandCategories(array $categoryIds, bool $withDescendants): array
    {
        if ($categoryIds === [] || ! $withDescendants) {
            return $categoryIds;
        }

        $expanded = $categoryIds;

        foreach (Category::query()->whereIn('id', $categoryIds)->get() as $category) {
            $expanded = array_merge(
                $expanded,
                Category::query()->whereDescendantOf($category)->pluck('id')->all(),
            );
        }

        return $this->intList($expanded);
    }

    /**
     * @param  mixed  $values
     * @return int[]
     */
    private function intList($values): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', (array) $values),
            fn (int $id) => $id > 0,
        )));
    }
}
