<?php

namespace App\Services\Promotion;

use App\Enums\PromoKind;
use App\Enums\PromotionRuleMode;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ErpPromotion;
use App\Models\Product;
use App\Models\PromotionRule;
use App\Models\Scopes\HiddenScope;

/**
 * Человекочитаемое описание правила акции.
 *
 * Одна и та же формулировка нужна в списке правил, в предпросмотре, а позже —
 * в корзине и на лендинге акции, поэтому она собрана в одном месте, а не
 * дублируется в шаблонах.
 *
 * Названия сущностей подтягиваются лениво и кэшируются на время запроса;
 * для списка правил вызывающий сначала прогревает кэш через warmUp().
 */
class PromotionRuleDescriber
{
    /** Со скольких условий перечисление в сводке сменяется свёрнутым видом. */
    private const SUMMARY_MAX_LINES = 3;

    /** Сравнение → знак для подписи. */
    private const OPERATORS = [
        '>=' => '≥',
        '>' => '>',
        '=' => '=',
        '<=' => '≤',
        '<' => '<',
    ];

    /** Тип группы 1С → название для маркетолога. */
    private const ERP_PROMOTION_LABELS = [
        ErpPromotion::TYPE_NEW => 'Новинки',
        ErpPromotion::TYPE_BESTSELLER => 'Хиты',
        ErpPromotion::TYPE_LIQUIDATION => 'Ликвидация',
    ];

    /** @var array<int, string> */
    private array $productNames = [];

    /** @var array<int, string> */
    private array $categoryNames = [];

    /** @var array<int, string> */
    private array $brandNames = [];

    /**
     * Загрузить названия всех сущностей, упомянутых в правилах, тремя запросами.
     *
     * @param  iterable<PromotionRule>  $rules
     */
    public function warmUp(iterable $rules): void
    {
        $products = [];
        $categories = [];
        $brands = [];

        foreach ($rules as $rule) {
            foreach ((array) ($rule->conditions['items'] ?? []) as $item) {
                $selector = (array) (((array) $item)['selector'] ?? []);
                $products = array_merge($products, $this->intList($selector['products'] ?? []));
                $categories = array_merge($categories, $this->intList($selector['categories'] ?? []));
                $brands = array_merge($brands, $this->intList($selector['brands'] ?? []));
            }

            foreach ((array) ($rule->rewards ?? []) as $reward) {
                $reward = (array) $reward;
                $products = array_merge(
                    $products,
                    $this->intList($reward['product_id'] ?? []),
                    $this->intList($reward['choices'] ?? []),
                );
            }
        }

        $this->loadNames(
            Product::withoutGlobalScope(HiddenScope::class),
            array_diff(array_unique($products), array_keys($this->productNames)),
            $this->productNames,
        );
        $this->loadNames(Category::query(), array_diff(array_unique($categories), array_keys($this->categoryNames)), $this->categoryNames);
        $this->loadNames(Brand::query(), array_diff(array_unique($brands), array_keys($this->brandNames)), $this->brandNames);
    }

    /**
     * Условия правила — по строке на условие.
     *
     * @return string[]
     */
    public function conditionLines(PromotionRule $rule): array
    {
        $lines = [];

        foreach (array_values((array) ($rule->conditions['items'] ?? [])) as $item) {
            $lines[] = $this->conditionLine((array) $item);
        }

        return $lines;
    }

    /**
     * Условия правила одной строкой — для колонки списка.
     *
     * Десяток позиций подряд в ячейку таблицы не влезает, поэтому длинный список
     * сворачивается в сводку: сколько условий, какие пороги и какие кратности.
     */
    public function conditionSummary(PromotionRule $rule): string
    {
        $items = array_values((array) ($rule->conditions['items'] ?? []));

        if ($items === []) {
            return 'Условия не заданы';
        }

        $mode = ($rule->conditions['mode'] ?? 'all') === 'any' ? 'any' : 'all';

        if (count($items) > self::SUMMARY_MAX_LINES) {
            return $this->collapsedConditionSummary($items, $mode);
        }

        return implode($mode === 'any' ? ' ИЛИ ' : ' И ', $this->conditionLines($rule));
    }

    /**
     * Сводка вместо перечисления: «15 условий (достаточно любого), количество 1–6 шт., кратность 1–6 шт.».
     *
     * Названия товаров сюда не попадают намеренно — пятнадцать наименований
     * не читаются ни в какой ячейке, а диапазоны порогов и кратностей отвечают
     * на главный вопрос «что здесь вообще настроено».
     *
     * @param  array<int, mixed>  $items
     */
    private function collapsedConditionSummary(array $items, string $mode): string
    {
        $count = count($items);
        $targets = [];
        $steps = [];
        $aggregates = [];

        foreach ($items as $item) {
            $item = (array) $item;
            $aggregate = ($item['aggregate'] ?? PromotionRule::AGGREGATE_QUANTITY) === PromotionRule::AGGREGATE_AMOUNT
                ? PromotionRule::AGGREGATE_AMOUNT
                : PromotionRule::AGGREGATE_QUANTITY;

            $aggregates[$aggregate] = true;
            $targets[] = (float) ($item['value'] ?? 0);

            if (is_numeric($item['per_value'] ?? null) && (float) $item['per_value'] > 0) {
                $steps[] = (float) $item['per_value'];
            }
        }

        $modeLabel = $mode === 'any' ? 'достаточно любого' : 'нужны все';
        $parts = [$count.' '.$this->plural($count, 'условие', 'условия', 'условий')." ({$modeLabel})"];

        if (count($aggregates) > 1) {
            $parts[] = 'пороги по количеству и по сумме';
        } else {
            $aggregate = (string) array_key_first($aggregates);
            $parts[] = ($aggregate === PromotionRule::AGGREGATE_AMOUNT ? 'сумма ' : 'количество ')
                .$this->range($targets, $aggregate);
        }

        if ($steps !== []) {
            $aggregate = count($aggregates) === 1
                ? (string) array_key_first($aggregates)
                : PromotionRule::AGGREGATE_QUANTITY;

            $parts[] = 'кратность '.$this->range($steps, $aggregate);
        }

        return implode(', ', $parts);
    }

    /**
     * «4 шт.» либо «1–6 шт.».
     *
     * @param  float[]  $values
     */
    private function range(array $values, string $aggregate): string
    {
        $min = min($values);
        $max = max($values);

        return abs($max - $min) < 0.005
            ? $this->formatAggregate($min, $aggregate)
            : $this->number($min).'–'.$this->formatAggregate($max, $aggregate);
    }

    /**
     * Русское склонение по числу: 1 условие, 2 условия, 5 условий.
     */
    private function plural(int $count, string $one, string $few, string $many): string
    {
        $mod100 = $count % 100;

        if ($mod100 >= 11 && $mod100 <= 14) {
            return $many;
        }

        return match ($count % 10) {
            1 => $one,
            2, 3, 4 => $few,
            default => $many,
        };
    }

    /**
     * Награды правила — по строке на награду.
     *
     * @return string[]
     */
    public function rewardLines(PromotionRule $rule): array
    {
        $lines = [];

        foreach (array_values((array) ($rule->rewards ?? [])) as $reward) {
            $lines[] = $this->rewardLine((array) $reward);
        }

        return $lines;
    }

    public function rewardSummary(PromotionRule $rule): string
    {
        $lines = $this->rewardLines($rule);

        return $lines === [] ? 'Награды не заданы' : implode('; ', $lines);
    }

    /**
     * Статус правила с учётом периода — для бейджа в списке.
     *
     * @return array{value: string, label: string}
     */
    public function status(PromotionRule $rule): array
    {
        if (! $rule->is_active) {
            return ['value' => 'disabled', 'label' => 'Выключено'];
        }

        $now = now();

        if ($rule->starts_at && $rule->starts_at->greaterThan($now)) {
            return ['value' => 'scheduled', 'label' => 'Не начата'];
        }

        if ($rule->ends_at && $rule->ends_at->lessThan($now)) {
            return ['value' => 'finished', 'label' => 'Завершена'];
        }

        return ['value' => 'active', 'label' => 'Активно'];
    }

    /**
     * Период действия одной строкой.
     */
    public function period(PromotionRule $rule): string
    {
        if (! $rule->starts_at && ! $rule->ends_at) {
            return '—';
        }

        if ($rule->starts_at && $rule->ends_at) {
            return 'с '.$rule->starts_at->format('d.m.Y').' по '.$rule->ends_at->format('d.m.Y');
        }

        return $rule->starts_at
            ? 'с '.$rule->starts_at->format('d.m.Y')
            : 'по '.$rule->ends_at->format('d.m.Y');
    }

    /**
     * Подсказка клиенту в корзине: сколько добрать до награды.
     *
     * Только «сколько добрать» — что именно он получит, показывается рядом
     * карточкой товара. Перечислять награды текстом нельзя: строка вида
     * «× 1 за 0 ₽ (не более 20 раз)» — это конфигурация правила, клиенту
     * она читается ребусом.
     */
    public function nearMissMessage(float $remaining, string $aggregate): string
    {
        return $aggregate === PromotionRule::AGGREGATE_AMOUNT
            ? 'Наберите акционных позиций ещё на '.$this->money($remaining)
            : 'Добавьте ещё '.$this->number($remaining).' шт. акционных позиций';
    }

    /**
     * Порог достигнут. В волне 1 выдачи нет, поэтому обещать автоматическую
     * промо-позицию нельзя — говорим прямо, что её добавит менеджер.
     */
    public function achievedMessage(PromotionRule $rule): string
    {
        return $rule->mode === PromotionRuleMode::ISSUE
            ? 'Условия акции выполнены.'
            : 'Условия акции выполнены — промо-позицию добавит менеджер.';
    }

    /**
     * Цена промо-позиции словами: клиенту важно «бесплатно», а не «0 ₽».
     */
    public function promoPriceLabel(float $price): string
    {
        return $price <= 0 ? 'бесплатно' : $this->money($price).' за шт.';
    }

    /**
     * Значение агрегата с единицей измерения: «150 000 ₽» или «10 шт.».
     */
    public function formatAggregate(float $value, string $aggregate): string
    {
        return $aggregate === PromotionRule::AGGREGATE_AMOUNT
            ? $this->money($value)
            : $this->number($value).' шт.';
    }

    // ────────────────────────────────────────────
    // Условия
    // ────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $item
     */
    private function conditionLine(array $item): string
    {
        $aggregate = ($item['aggregate'] ?? PromotionRule::AGGREGATE_QUANTITY) === PromotionRule::AGGREGATE_AMOUNT
            ? PromotionRule::AGGREGATE_AMOUNT
            : PromotionRule::AGGREGATE_QUANTITY;

        $prefix = $aggregate === PromotionRule::AGGREGATE_AMOUNT ? 'Сумма' : 'Количество';
        $operator = self::OPERATORS[(string) ($item['operator'] ?? '>=')] ?? '≥';
        $target = $this->formatAggregate((float) ($item['value'] ?? 0), $aggregate);

        $line = $prefix.' '.$this->selectorLabel((array) ($item['selector'] ?? [])).' '.$operator.' '.$target;

        // Своя кратность позиции — её вклад в число наград считается отдельно
        if (is_numeric($item['per_value'] ?? null) && (float) $item['per_value'] > 0) {
            $line .= ' (за каждые '.$this->formatAggregate((float) $item['per_value'], $aggregate).')';
        }

        return $line;
    }

    /**
     * Что именно попадает под селектор — словами.
     *
     * @param  array<string, mixed>  $selector
     */
    private function selectorLabel(array $selector): string
    {
        if (! empty($selector['whole_cart'])) {
            return 'всей корзины';
        }

        $parts = [];

        if ($products = $this->intList($selector['products'] ?? [])) {
            $parts[] = 'товаров '.$this->names($products, $this->productNames, Product::withoutGlobalScope(HiddenScope::class));
        }

        if ($categories = $this->intList($selector['categories'] ?? [])) {
            $label = 'категорий '.$this->names($categories, $this->categoryNames, Category::query());
            $parts[] = ! empty($selector['with_descendants']) ? $label.' (с подкатегориями)' : $label;
        }

        if ($brands = $this->intList($selector['brands'] ?? [])) {
            $parts[] = 'брендов '.$this->names($brands, $this->brandNames, Brand::query());
        }

        if ($tags = $this->stringList($selector['tags'] ?? [])) {
            $parts[] = 'товаров с тегами '.$this->quotedList($tags);
        }

        if ($erp = $this->stringList($selector['erp_promotions'] ?? [])) {
            $parts[] = 'групп 1С '.$this->quotedList(array_map(
                fn (string $type) => self::ERP_PROMOTION_LABELS[$type] ?? $type,
                $erp,
            ));
        }

        return $parts === [] ? 'акционных позиций' : implode(' или ', $parts);
    }

    // ────────────────────────────────────────────
    // Награды
    // ────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $reward
     */
    private function rewardLine(array $reward): string
    {
        $quantity = max(1, (int) ($reward['quantity'] ?? 1));
        $price = (float) ($reward['price'] ?? 0);

        if (($reward['type'] ?? PromotionRule::REWARD_TYPE_FIXED) === PromotionRule::REWARD_TYPE_CHOICE) {
            $choices = $this->intList($reward['choices'] ?? []);
            $what = $choices === []
                ? 'товар на выбор не указан'
                : 'на выбор: '.$this->names($choices, $this->productNames, Product::withoutGlobalScope(HiddenScope::class), 3);
        } else {
            $productId = (int) ($reward['product_id'] ?? 0);
            $what = $productId > 0
                ? $this->name($productId, $this->productNames, Product::withoutGlobalScope(HiddenScope::class))
                : 'товар не выбран';
        }

        $line = $what.' × '.$quantity.' за '.$this->money($price);

        if (($reward['multiply'] ?? PromotionRule::MULTIPLY_ONCE) === PromotionRule::MULTIPLY_PER_THRESHOLD) {
            $perValue = (float) ($reward['per_value'] ?? 0);
            $max = (int) ($reward['max_multiplier'] ?? 0);

            // Шаг может жить в позициях условия — тогда общего шага нет и писать
            // «на каждые 0» нельзя: строку видит и клиент в корзине
            if ($perValue > 0) {
                $line .= ', на каждые '.$this->number($perValue);
            }

            if ($max > 0) {
                $line .= ' (не более '.$max.' раз)';
            }
        }

        if (($reward['promo_kind'] ?? null) === PromoKind::SAMPLE->value) {
            $line .= ' — пробник';
        }

        return $line;
    }

    // ────────────────────────────────────────────
    // Названия и форматирование
    // ────────────────────────────────────────────

    /**
     * @param  int[]  $ids
     * @param  array<int, string>  $cache
     * @param  \Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function names(array $ids, array &$cache, $query, int $limit = 2): string
    {
        $this->loadNames($query, array_diff($ids, array_keys($cache)), $cache);

        $shown = array_slice($ids, 0, $limit);
        $labels = array_map(fn (int $id) => '«'.($cache[$id] ?? ('#'.$id)).'»', $shown);
        $rest = count($ids) - count($shown);

        return implode(', ', $labels).($rest > 0 ? ' и ещё '.$rest : '');
    }

    /**
     * @param  array<int, string>  $cache
     * @param  \Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function name(int $id, array &$cache, $query): string
    {
        $this->loadNames($query, array_diff([$id], array_keys($cache)), $cache);

        return $cache[$id] ?? ('#'.$id);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  int[]  $ids
     * @param  array<int, string>  $cache
     */
    private function loadNames($query, array $ids, array &$cache): void
    {
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return;
        }

        foreach ($query->whereIn('id', $ids)->pluck('name', 'id') as $id => $name) {
            $cache[(int) $id] = (string) $name;
        }
    }

    /**
     * @param  string[]  $values
     */
    private function quotedList(array $values, int $limit = 3): string
    {
        $shown = array_slice($values, 0, $limit);
        $rest = count($values) - count($shown);

        return implode(', ', array_map(fn (string $value) => '«'.$value.'»', $shown))
            .($rest > 0 ? ' и ещё '.$rest : '');
    }

    private function money(float $value): string
    {
        return $this->number($value).' ₽';
    }

    private function number(float $value): string
    {
        $decimals = abs($value - round($value)) < 0.005 ? 0 : 2;

        return number_format($value, $decimals, ',', ' ');
    }

    /**
     * @return int[]
     */
    private function intList(mixed $values): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', (array) $values),
            fn (int $id) => $id > 0,
        )));
    }

    /**
     * @return string[]
     */
    private function stringList(mixed $values): array
    {
        return array_values(array_filter(array_map(
            fn ($value) => is_string($value) ? trim($value) : '',
            (array) $values,
        )));
    }
}
