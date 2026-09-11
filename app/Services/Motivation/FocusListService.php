<?php

namespace App\Services\Motivation;

use App\Models\PayrollCalculation;
use App\Models\Product;
use App\Services\Analytics\AnalyticsContext;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\ShipmentAnalyticsService;
use App\Services\Crm\OpportunityService;
use App\Services\Crm\PlanScope;
use App\Services\Payroll\Dto\EffectiveParams;
use App\Services\Payroll\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * «Фокус-товары»: что продвигать и кому это можно предложить (карточка mot-30).
 *
 * Три блока: состав перечня на период, кому предложить, сколько уже заработано.
 * Заработанное берётся из снимка расчёта — той же строки П3, что на «Моём месяце».
 *
 * «Кому предложить» строится на том, что у нас есть: партнёр покупает у нас
 * категории, в которых перечень представлен. Закупок у других поставщиков
 * в данных нет и не будет — такого столбца на экране нет намеренно.
 *
 * Оговорка, которую экран обязан содержать: при нынешнем объёме продаж
 * собственных марок показатель даёт сотни рублей в месяц. Сумма показывается
 * честно, без укрупнения.
 */
class FocusListService
{
    private const ASSORTMENT_MONTHS = 12;

    public function __construct(
        private readonly FocusRangeResolver $focus,
        private readonly ShipmentAnalyticsService $analytics,
        private readonly PartnerAttributionResolver $attribution,
        private readonly OpportunityService $opportunities,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(int $managerId, CarbonInterface $month, ?PayrollCalculation $calculation): array
    {
        $period = CarbonImmutable::instance($month)->startOfMonth();
        $rules = $this->focus->rulesByProduct($period);
        $productIds = array_keys($rules);

        $params = $calculation === null ? [] : EffectiveParams::fromArray((array) $calculation->params_effective)->for('motivation_variable');
        $rateP3 = (float) ($params['rate_p3'] ?? config('motivation.default_parameters.rate_p3', 0));

        $products = $productIds === [] ? [] : Product::query()->whereIn('id', $productIds)->get(['id', 'name', 'sku', 'category_id'])->keyBy('id')->all();
        $stock = $productIds === [] ? [] : DB::table('product_warehouse')
            ->whereIn('product_id', $productIds)
            ->groupBy('product_id')
            ->pluck(DB::raw('SUM(quantity) AS total'), 'product_id')
            ->map(fn ($qty): int => (int) $qty)
            ->all();
        $roots = $this->rootCategories();

        $items = [];
        $focusRoots = [];

        foreach ($productIds as $productId) {
            $product = $products[$productId] ?? null;

            if ($product === null) {
                continue;
            }

            $rule = $rules[$productId];
            $root = $roots[(int) $product->category_id] ?? (int) $product->category_id;
            $focusRoots[$root] = true;

            $items[] = [
                'id' => $productId,
                'name' => (string) $product->name,
                'sku' => $product->sku,
                'rate' => $rule->rate === null ? $rateP3 : (float) $rule->rate,
                'own_rate' => $rule->rate !== null,
                'stock' => $stock[$productId] ?? 0,
                'starts_on' => $rule->starts_on->toDateString(),
                'ends_on' => $rule->ends_on?->toDateString(),
                'scope' => $rule->scope,
            ];
        }

        usort($items, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return [
            'month' => $period->toDateString(),
            'rate_p3' => $rateP3,
            'items' => $items,
            'partners' => $this->partners($managerId, $period, $productIds, array_keys($focusRoots), $roots, $rateP3),
            'earned' => $this->earned($calculation),
            'note' => 'Показатель П3 при нынешнем объёме продаж собственных марок даёт сотни рублей в месяц. Это надбавка за состав проданного, а не основной источник дохода.',
        ];
    }

    /**
     * Кому предложить: партнёры работника, покупающие категории, где перечень представлен.
     *
     * @param  list<int>  $productIds
     * @param  list<int>  $focusRoots
     * @param  array<int, int>  $roots
     * @return list<array<string, mixed>>
     */
    private function partners(int $managerId, CarbonImmutable $period, array $productIds, array $focusRoots, array $roots, float $rateP3): array
    {
        $names = $this->attribution->partnersOf($managerId, $period->endOfMonth());
        $ids = array_keys($names);

        if ($ids === [] || $focusRoots === []) {
            return [];
        }

        $focusThisMonth = $this->focusPurchases($ids, $productIds, $period);
        $categoryAmounts = $this->categoryAmounts($ids, $period, $roots);
        $signals = $this->opportunities->signals($period, PlanScope::manager($managerId, $ids, (string) $managerId));
        $rootNames = DB::table('categories')->whereIn('id', $focusRoots)->pluck('name', 'id')->all();

        $rows = [];

        foreach ($ids as $id) {
            $matched = array_intersect_key($categoryAmounts[$id] ?? [], array_flip($focusRoots));

            if ($matched === []) {
                continue;   // категории перечня не покупает — предлагать не на чем
            }

            // Потенциал — обычный месячный объём партнёра в категориях перечня.
            $potential = Money::round(array_sum($matched) / self::ASSORTMENT_MONTHS);

            $rows[] = [
                'id' => $id,
                'name' => (string) ($signals[$id]['name'] ?? $names[$id]),
                'focus_this_month' => Money::round($focusThisMonth[$id] ?? 0.0),
                'categories' => array_map(fn (int $root): string => (string) ($rootNames[$root] ?? "Категория #{$root}"), array_keys($matched)),
                'potential' => $potential,
                'your_gain' => Money::round($potential * $rateP3),
                'last_purchase_on' => isset($signals[$id]['last_purchase_at']) ? CarbonImmutable::parse((string) $signals[$id]['last_purchase_at'])->toDateString() : null,
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['your_gain'] <=> $a['your_gain']);

        return $rows;
    }

    /**
     * @param  list<int>  $partnerIds
     * @param  list<int>  $productIds
     * @return array<int, float>
     */
    private function focusPurchases(array $partnerIds, array $productIds, CarbonImmutable $period): array
    {
        if ($productIds === []) {
            return [];
        }

        $ctx = AnalyticsContext::forScope($partnerIds, AnalyticsContext::DATE_ERP, null);

        if ($ctx->isEmpty()) {
            return [];
        }

        $amounts = [];

        foreach ($this->analytics->byPartner($ctx, new AnalyticsFilters(
            dateFrom: $period->startOfDay(),
            dateTo: $period->endOfMonth()->endOfDay(),
            productIds: $productIds,
        ), null) as $row) {
            if ($row['partner_id'] !== null) {
                $amounts[(int) $row['partner_id']] = (float) $row['amount'];
            }
        }

        return $amounts;
    }

    /**
     * Закупки партнёров по корневым категориям за год: partner_id → root → сумма.
     *
     * @param  list<int>  $partnerIds
     * @param  array<int, int>  $roots
     * @return array<int, array<int, float>>
     */
    private function categoryAmounts(array $partnerIds, CarbonImmutable $period, array $roots): array
    {
        $rows = DB::table('shipment_items')
            ->join('shipments', 'shipments.id', '=', 'shipment_items.shipment_id')
            ->join('products', 'products.id', '=', 'shipment_items.product_id')
            ->whereIn('shipments.user_id', $partnerIds)
            ->whereNotNull('products.category_id')
            ->whereBetween('shipments.erp_created_at', [
                $period->subMonths(self::ASSORTMENT_MONTHS)->startOfDay(),
                $period->endOfMonth()->endOfDay(),
            ])
            ->groupBy('shipments.user_id', 'products.category_id')
            ->get(['shipments.user_id', 'products.category_id', DB::raw('SUM(shipment_items.total) AS total')]);

        $result = [];

        foreach ($rows as $row) {
            $root = $roots[(int) $row->category_id] ?? (int) $row->category_id;
            $result[(int) $row->user_id][$root] = ($result[(int) $row->user_id][$root] ?? 0.0) + (float) $row->total;
        }

        return $result;
    }

    /**
     * @return array<int, int>
     */
    private function rootCategories(): array
    {
        $parents = DB::table('categories')->pluck('parent_id', 'id')->all();
        $roots = [];

        foreach (array_keys($parents) as $id) {
            $cursor = (int) $id;
            $guard = 0;

            while ($parents[$cursor] !== null && $guard++ < 32) {
                $cursor = (int) $parents[$cursor];
            }

            $roots[(int) $id] = $cursor;
        }

        return $roots;
    }

    /**
     * @return array{revenue: float, amount: float, positions: int}|null
     */
    private function earned(?PayrollCalculation $calculation): ?array
    {
        if ($calculation === null) {
            return null;
        }

        foreach ((array) data_get($calculation->breakdown, 'components', []) as $component) {
            if (! is_array($component) || ($component['key'] ?? null) !== 'motivation_variable') {
                continue;
            }

            foreach ((array) ($component['children'] ?? []) as $child) {
                if (is_array($child) && ($child['key'] ?? null) === 'p3') {
                    return [
                        'revenue' => (float) ($child['meta']['revenue'] ?? 0),
                        'amount' => (float) ($child['amount'] ?? 0),
                        'positions' => (int) ($child['meta']['positions'] ?? 0),
                    ];
                }
            }
        }

        return null;
    }
}
