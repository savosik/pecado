<?php

namespace App\Services\Motivation;

use App\Models\Category;
use App\Models\Motivation\MotivationFocusRule;
use App\Models\Motivation\MotivationFocusSnapshotItem;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Состав Фокус-перечня на расчётный период: что именно считается фокусным товаром.
 *
 * Порядок источников важен. Снимок периода — истина, если он есть: правка правил
 * задним числом не должна менять уже посчитанный месяц. Если снимка нет (текущий
 * месяц ещё не закрыт), состав разворачивается из действующих правил.
 *
 * Правило на бренд разворачивается в сотни товаров, правило на категорию — вместе
 * с потомками по дереву: перечень ведётся правилами именно затем, чтобы руководитель
 * не перечислял товары руками.
 */
class FocusRangeResolver
{
    /**
     * Позиции перечня на период: product_id → ставка (null — общая ставка приказа).
     *
     * @return array<int, float|null>
     */
    public function itemsFor(CarbonInterface $month): array
    {
        $period = CarbonImmutable::instance($month)->startOfMonth();

        $snapshot = MotivationFocusSnapshotItem::query()
            ->forPeriod($period)
            ->get(['product_id', 'rate']);

        if ($snapshot->isNotEmpty()) {
            // В снимке ставка задана всегда: это и есть та ставка, что действовала
            // в периоде. Изменение приказа задним числом её не перепишет.
            $items = [];
            foreach ($snapshot as $row) {
                $items[(int) $row->product_id] = (float) $row->rate;
            }

            return $items;
        }

        return $this->fromRules($period);
    }

    /**
     * Разворот действующих правил в позиции.
     *
     * Правило со своей ставкой перекрывает общее: если товар попал и в правило
     * на бренд, и в правило на позицию с повышенной ставкой, действует повышенная.
     *
     * @return array<int, float|null>
     */
    public function fromRules(CarbonInterface $month): array
    {
        $items = [];

        foreach ($this->rulesByProduct($month) as $productId => $rule) {
            $items[$productId] = $rule->rate === null ? null : (float) $rule->rate;
        }

        return $items;
    }

    /**
     * Какое правило применилось к каждому товару в периоде: product_id → правило.
     *
     * Нужно экрану перечня — показать, с какого числа позиция в перечне и когда
     * выходит, — и расчёту, которому важна только ставка.
     *
     * @return array<int, MotivationFocusRule>
     */
    public function rulesByProduct(CarbonInterface $month): array
    {
        $period = CarbonImmutable::instance($month)->startOfMonth();

        // Правило действует в периоде, если пересекается с ним хотя бы одним днём:
        // позиция, включённая в перечень с середины месяца, работает с этого дня.
        $rules = MotivationFocusRule::query()
            ->whereDate('starts_on', '<=', $period->endOfMonth())
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $period))
            ->orderBy('scope')
            ->get();

        $byProduct = [];

        foreach ($rules as $rule) {
            foreach ($this->productIds($rule) as $productId) {
                // Своя ставка правила приоритетнее общей; иначе первое совпадение.
                if ($rule->rate !== null || ! array_key_exists($productId, $byProduct)) {
                    $byProduct[$productId] = $rule;
                }
            }
        }

        return $byProduct;
    }

    /**
     * @return list<int>
     */
    private function productIds(MotivationFocusRule $rule): array
    {
        return match ($rule->scope) {
            MotivationFocusRule::SCOPE_PRODUCT => [(int) $rule->target_id],
            MotivationFocusRule::SCOPE_BRAND => Product::query()
                ->where('brand_id', $rule->target_id)
                ->pluck('id')->map('intval')->all(),
            MotivationFocusRule::SCOPE_CATEGORY => $this->productsOfCategoryTree((int) $rule->target_id),
            default => [],
        };
    }

    /**
     * Товары категории вместе с потомками по дереву.
     *
     * @return list<int>
     */
    private function productsOfCategoryTree(int $categoryId): array
    {
        $category = Category::query()->find($categoryId);

        if ($category === null) {
            return [];
        }

        $ids = Category::query()
            ->descendantsAndSelf($categoryId)
            ->pluck('id')
            ->map('intval')
            ->all();

        return Product::query()
            ->whereIn('category_id', $ids === [] ? [$categoryId] : $ids)
            ->pluck('id')->map('intval')->all();
    }
}
