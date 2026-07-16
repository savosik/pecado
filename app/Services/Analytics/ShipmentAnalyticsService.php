<?php

namespace App\Services\Analytics;

use App\Models\Category;
use App\Models\Currency;
use App\Models\User;
use App\Services\CurrencyService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Агрегации по реализациям (отгрузкам) для дашбордов «Аналитика».
 *
 * Скоуп данных задаётся через AnalyticsContext:
 *  - кабинет партнёра — один пользователь (shipments.user_id = id, дата отгрузки);
 *  - CRM менеджер/РОП — набор клиентов (shipments.user_id IN (...), дата документа 1С).
 *
 * Все агрегации идут по shipment_items.total в валюте, конвертированной
 * в RUB через currencies.exchange_rate, далее в PHP при необходимости
 * конвертируются в валюту контекста через CurrencyService (в CRM — рубли).
 */
class ShipmentAnalyticsService
{
    public function __construct(
        private readonly CurrencyService $currencyService,
    ) {}

    /**
     * KPI-блок: количество поставок, сумма, средний чек, штук, контрагентов.
     *
     * @return array<string, int|float>
     */
    public function metrics(AnalyticsContext $ctx, AnalyticsFilters $filters): array
    {
        $row = (array) $this->itemsBaseQuery($ctx, $filters)
            ->selectRaw('
                COUNT(DISTINCT shipments.id)      AS shipments_count,
                COALESCE(SUM(shipment_items.quantity), 0) AS items_total_qty,
                COALESCE(SUM(shipment_items.total * COALESCE(currencies.exchange_rate, 1)), 0) AS total_in_rub,
                COUNT(DISTINCT COALESCE(CAST(shipments.company_id AS CHAR), shipments.tax_id)) AS contractors_count
            ')
            ->first();

        $totalRub = (float) ($row['total_in_rub'] ?? 0);
        $shipmentsCount = (int) ($row['shipments_count'] ?? 0);
        $totalConverted = $this->convertFromRub($totalRub, $ctx);

        return [
            'shipments_count' => $shipmentsCount,
            'total_amount' => round($totalConverted, 2),
            'avg_check' => $shipmentsCount > 0 ? round($totalConverted / $shipmentsCount, 2) : 0.0,
            'items_total_qty' => (int) ($row['items_total_qty'] ?? 0),
            'contractors_count' => (int) ($row['contractors_count'] ?? 0),
        ];
    }

    /**
     * Топ-N брендов по сумме отгрузок.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function byBrand(AnalyticsContext $ctx, AnalyticsFilters $filters, int $limit = 50): Collection
    {
        $rows = $this->itemsBaseQuery($ctx, $filters)
            ->leftJoin('products as products_brand', 'products_brand.id', '=', 'shipment_items.product_id')
            ->selectRaw("
                COALESCE(NULLIF(shipment_items.brand_name_snapshot, ''), 'Без бренда') AS label,
                MAX(products_brand.brand_id) AS brand_id,
                COALESCE(SUM(shipment_items.total * COALESCE(currencies.exchange_rate, 1)), 0) AS total_in_rub,
                COALESCE(SUM(shipment_items.quantity), 0) AS qty,
                COUNT(DISTINCT shipments.id) AS shipments_count,
                COUNT(DISTINCT COALESCE(CAST(shipments.company_id AS CHAR), shipments.tax_id)) AS contractors_count
            ")
            ->groupBy('label')
            ->orderByDesc('total_in_rub')
            ->limit($limit)
            ->get();

        return collect($rows)->map(fn ($row) => [
            'key' => (string) $row->label,
            'label' => (string) $row->label,
            'brand_id' => $row->brand_id ? (int) $row->brand_id : null,
            'amount' => round($this->convertFromRub((float) $row->total_in_rub, $ctx), 2),
            'qty' => (int) $row->qty,
            'shipments_count' => (int) $row->shipments_count,
            'contractors_count' => (int) $row->contractors_count,
        ]);
    }

    /**
     * Топ-N категорий с полным путём (категория/подкатегория/.../лист).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function byCategory(AnalyticsContext $ctx, AnalyticsFilters $filters, int $limit = 50): Collection
    {
        $rows = $this->itemsBaseQuery($ctx, $filters)
            ->leftJoin('products', 'products.id', '=', 'shipment_items.product_id')
            ->selectRaw('
                products.category_id AS category_id,
                COALESCE(SUM(shipment_items.total * COALESCE(currencies.exchange_rate, 1)), 0) AS total_in_rub,
                COALESCE(SUM(shipment_items.quantity), 0) AS qty,
                COUNT(DISTINCT shipments.id) AS shipments_count,
                COUNT(DISTINCT COALESCE(CAST(shipments.company_id AS CHAR), shipments.tax_id)) AS contractors_count
            ')
            ->groupBy('products.category_id')
            ->orderByDesc('total_in_rub')
            ->limit($limit)
            ->get();

        $categoryIds = $rows->pluck('category_id')->filter()->unique()->values()->all();
        $pathById = $this->buildCategoryPaths($categoryIds);

        return $rows->map(function ($row) use ($pathById, $ctx) {
            $categoryId = $row->category_id ? (int) $row->category_id : null;
            $label = $categoryId !== null
                ? ($pathById[$categoryId] ?? ('Категория #'.$categoryId))
                : 'Без категории';

            return [
                'key' => $categoryId !== null ? (string) $categoryId : 'none',
                'label' => $label,
                'amount' => round($this->convertFromRub((float) $row->total_in_rub, $ctx), 2),
                'qty' => (int) $row->qty,
                'shipments_count' => (int) $row->shipments_count,
                'contractors_count' => (int) $row->contractors_count,
            ];
        });
    }

    /**
     * Топ-N контрагентов (юрлиц).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function byContractor(AnalyticsContext $ctx, AnalyticsFilters $filters, int $limit = 50): Collection
    {
        $rows = $this->itemsBaseQuery($ctx, $filters)
            ->leftJoin('companies', 'companies.id', '=', 'shipments.company_id')
            ->selectRaw('
                shipments.company_id AS company_id,
                shipments.tax_id     AS tax_id,
                MAX(companies.name)  AS company_name,
                MAX(companies.legal_name) AS legal_name,
                COALESCE(SUM(shipment_items.total * COALESCE(currencies.exchange_rate, 1)), 0) AS total_in_rub,
                COALESCE(SUM(shipment_items.quantity), 0) AS qty,
                COUNT(DISTINCT shipments.id) AS shipments_count
            ')
            ->groupBy('shipments.company_id', 'shipments.tax_id')
            ->orderByDesc('total_in_rub')
            ->limit($limit)
            ->get();

        return $rows->map(function ($row) use ($ctx) {
            $companyId = $row->company_id ? (int) $row->company_id : null;
            $taxId = $row->tax_id ?: null;

            $label = $row->company_name
                ?: ($row->legal_name
                    ?: ($taxId
                        ? "ИНН {$taxId} (не привязано)"
                        : 'Контрагент не указан'));

            return [
                'key' => $companyId !== null ? "id:{$companyId}" : ($taxId ? "tax:{$taxId}" : 'none'),
                'company_id' => $companyId,
                'tax_id' => $taxId,
                'label' => $label,
                'amount' => round($this->convertFromRub((float) $row->total_in_rub, $ctx), 2),
                'qty' => (int) $row->qty,
                'shipments_count' => (int) $row->shipments_count,
            ];
        });
    }

    /**
     * Топ-N товаров.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function byProduct(AnalyticsContext $ctx, AnalyticsFilters $filters, int $limit = 100): Collection
    {
        $rows = $this->itemsBaseQuery($ctx, $filters)
            ->leftJoin('products', 'products.id', '=', 'shipment_items.product_id')
            ->selectRaw("
                shipment_items.product_id AS product_id,
                COALESCE(NULLIF(shipment_items.product_name_snapshot, ''), MAX(products.name)) AS product_name,
                MAX(products.sku)  AS sku,
                MAX(products.slug) AS slug,
                COALESCE(SUM(shipment_items.total * COALESCE(currencies.exchange_rate, 1)), 0) AS total_in_rub,
                COALESCE(SUM(shipment_items.quantity), 0) AS qty,
                COUNT(DISTINCT shipments.id) AS shipments_count,
                COUNT(DISTINCT COALESCE(CAST(shipments.company_id AS CHAR), shipments.tax_id)) AS contractors_count
            ")
            ->groupBy('shipment_items.product_id', 'shipment_items.product_name_snapshot')
            ->orderByDesc('total_in_rub')
            ->limit($limit)
            ->get();

        return $rows->map(function ($row) use ($ctx) {
            return [
                'key' => $row->product_id ? (string) $row->product_id : 'none',
                'product_id' => $row->product_id ? (int) $row->product_id : null,
                'sku' => $row->sku,
                'slug' => $row->slug,
                'label' => $row->product_name ?: 'Товар не указан',
                'amount' => round($this->convertFromRub((float) $row->total_in_rub, $ctx), 2),
                'qty' => (int) $row->qty,
                'shipments_count' => (int) $row->shipments_count,
                'contractors_count' => (int) $row->contractors_count,
            ];
        });
    }

    /**
     * Топ-N менеджеров по сумме отгрузок их клиентов.
     * Разрез доступен только в CRM (РОП) — менеджер клиента берётся
     * через users.personal_manager_id закреплённого за отгрузкой пользователя.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function byManager(AnalyticsContext $ctx, AnalyticsFilters $filters, int $limit = 100): Collection
    {
        $rows = $this->itemsBaseQuery($ctx, $filters)
            ->join('users as buyer', 'buyer.id', '=', 'shipments.user_id')
            ->leftJoin('personal_managers as pm', 'pm.id', '=', 'buyer.personal_manager_id')
            ->selectRaw("
                COALESCE(pm.id, 0) AS manager_id,
                COALESCE(NULLIF(pm.name, ''), 'Без менеджера') AS label,
                COALESCE(SUM(shipment_items.total * COALESCE(currencies.exchange_rate, 1)), 0) AS total_in_rub,
                COALESCE(SUM(shipment_items.quantity), 0) AS qty,
                COUNT(DISTINCT shipments.id) AS shipments_count,
                COUNT(DISTINCT COALESCE(CAST(shipments.company_id AS CHAR), shipments.tax_id)) AS contractors_count,
                COUNT(DISTINCT shipments.user_id) AS clients_count
            ")
            ->groupBy('manager_id', 'label')
            ->orderByDesc('total_in_rub')
            ->limit($limit)
            ->get();

        return collect($rows)->map(fn ($row) => [
            'key' => (string) $row->manager_id,
            'manager_id' => $row->manager_id ? (int) $row->manager_id : null,
            'label' => (string) $row->label,
            'amount' => round($this->convertFromRub((float) $row->total_in_rub, $ctx), 2),
            'qty' => (int) $row->qty,
            'shipments_count' => (int) $row->shipments_count,
            'contractors_count' => (int) $row->contractors_count,
            'clients_count' => (int) $row->clients_count,
        ]);
    }

    /**
     * Динамика суммы и количества по периоду. Bucket выбирается автоматически.
     *
     * @return array{bucket: string, points: array<int, array<string, mixed>>}
     */
    public function timeSeries(AnalyticsContext $ctx, AnalyticsFilters $filters): array
    {
        $rowsByDay = $this->itemsBaseQuery($ctx, $filters)
            ->selectRaw('
                DATE('.$ctx->dateColumn.') AS day,
                COALESCE(SUM(shipment_items.total * COALESCE(currencies.exchange_rate, 1)), 0) AS total_in_rub,
                COALESCE(SUM(shipment_items.quantity), 0) AS qty,
                COUNT(DISTINCT shipments.id) AS shipments_count
            ')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $bucket = $filters->bucket();
        $accumulated = [];

        foreach ($rowsByDay as $row) {
            if (! $row->day) {
                continue;
            }
            $day = CarbonImmutable::parse($row->day);
            $key = match ($bucket) {
                'week' => $day->startOfWeek()->toDateString(),
                'month' => $day->startOfMonth()->toDateString(),
                default => $day->toDateString(),
            };

            if (! isset($accumulated[$key])) {
                $accumulated[$key] = [
                    'period' => $key,
                    'amount_rub' => 0.0,
                    'qty' => 0,
                    'shipments_count' => 0,
                ];
            }
            $accumulated[$key]['amount_rub'] += (float) $row->total_in_rub;
            $accumulated[$key]['qty'] += (int) $row->qty;
            $accumulated[$key]['shipments_count'] += (int) $row->shipments_count;
        }

        ksort($accumulated);

        $points = array_map(function (array $bucketRow) use ($ctx) {
            return [
                'period' => $bucketRow['period'],
                'amount' => round($this->convertFromRub($bucketRow['amount_rub'], $ctx), 2),
                'qty' => $bucketRow['qty'],
                'shipments_count' => $bucketRow['shipments_count'],
            ];
        }, array_values($accumulated));

        return [
            'bucket' => $bucket,
            'points' => $points,
        ];
    }

    /**
     * Опции для фильтров кабинета: компании пользователя, бренды и категории
     * из его реальных отгрузок (без сторонних).
     *
     * @return array{companies: array<int, array<string, mixed>>, brands: array<int, array<string, mixed>>, categories: array<int, array<string, mixed>>}
     */
    public function filterOptions(User $user): array
    {
        $companies = $user->companies()
            ->orderBy('name')
            ->get(['id', 'name', 'legal_name', 'tax_id'])
            ->map(fn ($c) => [
                'id' => (int) $c->id,
                'name' => $c->name ?: $c->legal_name ?: ('ИНН '.$c->tax_id),
                'tax_id' => $c->tax_id,
            ])
            ->all();

        $brands = DB::table('shipments')
            ->join('shipment_items', 'shipment_items.shipment_id', '=', 'shipments.id')
            ->leftJoin('products', 'products.id', '=', 'shipment_items.product_id')
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->where('shipments.user_id', $user->id)
            ->whereNotNull('brands.id')
            ->select('brands.id', 'brands.name')
            ->distinct()
            ->orderBy('brands.name')
            ->get()
            ->map(fn ($b) => ['id' => (int) $b->id, 'name' => (string) $b->name])
            ->all();

        $categoryIds = DB::table('shipments')
            ->join('shipment_items', 'shipment_items.shipment_id', '=', 'shipments.id')
            ->leftJoin('products', 'products.id', '=', 'shipment_items.product_id')
            ->where('shipments.user_id', $user->id)
            ->whereNotNull('products.category_id')
            ->distinct()
            ->pluck('products.category_id')
            ->filter()
            ->all();

        $pathById = $this->buildCategoryPaths($categoryIds);
        $categories = collect($pathById)
            ->map(fn ($path, $id) => ['id' => (int) $id, 'name' => $path])
            ->sortBy('name')
            ->values()
            ->all();

        return [
            'companies' => $companies,
            'brands' => $brands,
            'categories' => $categories,
        ];
    }

    /**
     * Опции для фильтров CRM: бренды, категории и контрагенты из отгрузок
     * в пределах скоупа (клиентов менеджера/отдела). Менеджеры отдаются отдельно
     * контроллером (только для РОП).
     *
     * @return array{companies: array<int, array<string, mixed>>, brands: array<int, array<string, mixed>>, categories: array<int, array<string, mixed>>}
     */
    public function filterOptionsForScope(AnalyticsContext $ctx): array
    {
        if ($ctx->isEmpty()) {
            return ['companies' => [], 'brands' => [], 'categories' => []];
        }

        $companies = DB::table('shipments')
            ->leftJoin('companies', 'companies.id', '=', 'shipments.company_id')
            ->whereNull('shipments.deleted_at')
            ->whereIn('shipments.user_id', $ctx->userIds)
            ->whereNotNull('shipments.company_id')
            ->select('companies.id', 'companies.name', 'companies.legal_name', 'companies.tax_id')
            ->distinct()
            ->get()
            ->map(fn ($c) => [
                'id' => (int) $c->id,
                'name' => $c->name ?: $c->legal_name ?: ('ИНН '.$c->tax_id),
                'tax_id' => $c->tax_id,
            ])
            ->sortBy('name')
            ->values()
            ->all();

        $brands = DB::table('shipments')
            ->join('shipment_items', 'shipment_items.shipment_id', '=', 'shipments.id')
            ->leftJoin('products', 'products.id', '=', 'shipment_items.product_id')
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->whereNull('shipments.deleted_at')
            ->whereIn('shipments.user_id', $ctx->userIds)
            ->whereNotNull('brands.id')
            ->select('brands.id', 'brands.name')
            ->distinct()
            ->orderBy('brands.name')
            ->get()
            ->map(fn ($b) => ['id' => (int) $b->id, 'name' => (string) $b->name])
            ->all();

        $categoryIds = DB::table('shipments')
            ->join('shipment_items', 'shipment_items.shipment_id', '=', 'shipments.id')
            ->leftJoin('products', 'products.id', '=', 'shipment_items.product_id')
            ->whereNull('shipments.deleted_at')
            ->whereIn('shipments.user_id', $ctx->userIds)
            ->whereNotNull('products.category_id')
            ->distinct()
            ->pluck('products.category_id')
            ->filter()
            ->all();

        $pathById = $this->buildCategoryPaths($categoryIds);
        $categories = collect($pathById)
            ->map(fn ($path, $id) => ['id' => (int) $id, 'name' => $path])
            ->sortBy('name')
            ->values()
            ->all();

        return [
            'companies' => $companies,
            'brands' => $brands,
            'categories' => $categories,
        ];
    }

    /**
     * Базовый JOIN-запрос: shipment_items + shipments + currencies + фильтры.
     */
    private function itemsBaseQuery(AnalyticsContext $ctx, AnalyticsFilters $filters): Builder
    {
        $query = DB::table('shipment_items')
            ->join('shipments', 'shipments.id', '=', 'shipment_items.shipment_id')
            ->leftJoin('currencies', 'currencies.code', '=', 'shipments.currency_code')
            ->whereNull('shipments.deleted_at')
            ->whereIn('shipments.user_id', $ctx->userIds)
            ->where($ctx->dateColumn, '>=', $filters->dateFrom->format('Y-m-d H:i:s'))
            ->where($ctx->dateColumn, '<=', $filters->dateTo->format('Y-m-d H:i:s'));

        if ($filters->companyIds !== []) {
            $query->whereIn('shipments.company_id', $filters->companyIds);
        }

        if ($filters->productIds !== []) {
            $query->whereIn('shipment_items.product_id', $filters->productIds);
        }

        if ($filters->brandIds !== [] || $filters->categoryIds !== [] || $filters->sku !== null) {
            $query->leftJoin('products as products_filter', 'products_filter.id', '=', 'shipment_items.product_id');

            if ($filters->brandIds !== []) {
                $query->whereIn('products_filter.brand_id', $filters->brandIds);
            }
            if ($filters->categoryIds !== []) {
                $query->whereIn('products_filter.category_id', $filters->categoryIds);
            }
            if ($filters->sku !== null) {
                $query->where('products_filter.sku', 'like', '%'.$filters->sku.'%');
            }
        }

        return $query;
    }

    /**
     * Строит «Корень / Дочка / .../ Текущая» для набора category_id.
     *
     * @param  array<int, int>  $categoryIds
     * @return array<int, string>
     */
    private function buildCategoryPaths(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $categories = Category::query()
            ->whereIn('id', $categoryIds)
            ->with(['ancestors' => fn ($q) => $q->orderBy('_lft')])
            ->get(['id', 'name', '_lft', '_rgt', 'parent_id']);

        $result = [];
        foreach ($categories as $category) {
            $names = $category->ancestors
                ->sortBy('_lft')
                ->pluck('name')
                ->push($category->name)
                ->all();
            $result[(int) $category->id] = implode(' / ', $names);
        }

        return $result;
    }

    private function convertFromRub(float $amountInRub, AnalyticsContext $ctx): float
    {
        $currency = $ctx->currency;

        if (! $currency || $currency->is_base) {
            return $amountInRub;
        }

        return $this->currencyService->convertFromBase($amountInRub, $currency);
    }

    public function userCurrency(User $user): ?Currency
    {
        return $user->region?->currency;
    }

    /**
     * ABC/XYZ-анализ за последние 12 месяцев.
     *
     * ABC — по доле в обороте: A ≤80%, B ≤95%, C — остальные.
     * XYZ — по стабильности спроса (коэффициент вариации помесячных сумм):
     *   X ≤25%, Y ≤50%, Z >50% (или активность <3 мес из 12).
     *
     * Период из $filters игнорируется (всегда 12 месяцев — иначе нечего усреднять),
     * остальные фильтры (компания/бренд/категория/артикул/товары) применяются.
     *
     * @param  'brand'|'category'|'product'  $dimension
     * @return array{
     *   dimension: string,
     *   currency: array<string,string>,
     *   matrix: array<string, int>,
     *   rows: array<int, array<string, mixed>>
     * }
     */
    public function abcXyz(AnalyticsContext $ctx, string $dimension, ?AnalyticsFilters $filters = null): array
    {
        $end = CarbonImmutable::now()->endOfMonth();
        $start = $end->subMonths(11)->startOfMonth();

        $monthsTimeline = [];
        for ($m = $start; $m->lessThanOrEqualTo($end); $m = $m->addMonth()) {
            $monthsTimeline[] = $m->format('Y-m');
        }

        // Подменяем период фильтра на фиксированные 12 месяцев,
        // остальные фильтры (компания/бренд/категория/артикул/товары) сохраняем.
        $effectiveFilters = new AnalyticsFilters(
            dateFrom: $start->startOfDay(),
            dateTo: $end->endOfDay(),
            companyIds: $filters?->companyIds ?? [],
            brandIds: $filters?->brandIds ?? [],
            categoryIds: $filters?->categoryIds ?? [],
            sku: $filters?->sku,
            productIds: $filters?->productIds ?? [],
        );

        $rows = $this->fetchDimensionMonthly($ctx, $dimension, $effectiveFilters);

        // Конвертация суммы в валюту контекста выполняется уже в этом методе
        // через JOIN на currencies (см. fetchDimensionMonthly).

        $totalAll = array_sum(array_column($rows, 'total_amount'));
        if ($totalAll <= 0) {
            return [
                'dimension' => $dimension,
                'currency' => [
                    'code' => $ctx->currency?->code ?? 'RUB',
                    'symbol' => $ctx->currency?->symbol ?? '₽',
                ],
                'matrix' => [],
                'rows' => [],
            ];
        }

        // ABC: сортируем по сумме DESC и накапливаем долю
        usort($rows, fn ($a, $b) => $b['total_amount'] <=> $a['total_amount']);
        $cumulative = 0;
        foreach ($rows as &$r) {
            $cumulative += $r['total_amount'];
            $share = $r['total_amount'] / $totalAll;
            $cumShare = $cumulative / $totalAll;
            $r['share_pct'] = round($share * 100, 2);
            $r['cum_share_pct'] = round($cumShare * 100, 2);
            $r['abc'] = $cumShare <= 0.8 ? 'A' : ($cumShare <= 0.95 ? 'B' : 'C');
        }
        unset($r);

        // XYZ: коэффициент вариации помесячных сумм по timeline (12 точек, нули учитываются)
        foreach ($rows as &$r) {
            $monthly = [];
            foreach ($monthsTimeline as $monthKey) {
                $monthly[] = (float) ($r['monthly'][$monthKey] ?? 0);
            }
            $activeMonths = count(array_filter($monthly, fn ($v) => $v > 0));
            $mean = array_sum($monthly) / count($monthly);
            $variance = 0.0;
            foreach ($monthly as $v) {
                $variance += ($v - $mean) ** 2;
            }
            $variance /= count($monthly);
            $std = sqrt($variance);
            $cv = $mean > 0 ? ($std / $mean) * 100 : 0;

            $r['active_months'] = $activeMonths;
            $r['cv_pct'] = round($cv, 1);
            $r['xyz'] = $activeMonths < 3
                ? 'Z'
                : ($cv <= 25 ? 'X' : ($cv <= 50 ? 'Y' : 'Z'));
            $r['cell'] = $r['abc'].$r['xyz'];
            $r['strategy'] = $this->abcXyzStrategy($r['cell']);
            $r['monthly_series'] = $monthly;
            unset($r['monthly']);
        }
        unset($r);

        $matrix = [
            'AX' => 0, 'AY' => 0, 'AZ' => 0,
            'BX' => 0, 'BY' => 0, 'BZ' => 0,
            'CX' => 0, 'CY' => 0, 'CZ' => 0,
        ];
        foreach ($rows as $r) {
            $matrix[$r['cell']] = ($matrix[$r['cell']] ?? 0) + 1;
        }

        return [
            'dimension' => $dimension,
            'currency' => [
                'code' => $ctx->currency?->code ?? 'RUB',
                'symbol' => $ctx->currency?->symbol ?? '₽',
            ],
            'matrix' => $matrix,
            'months' => $monthsTimeline,
            'rows' => $rows,
        ];
    }

    /**
     * Сырые данные для ABC/XYZ — суммы по объекту в разрезе календарных месяцев.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchDimensionMonthly(
        AnalyticsContext $ctx,
        string $dimension,
        AnalyticsFilters $filters,
    ): array {
        $base = $this->itemsBaseQuery($ctx, $filters);
        $dateExpr = 'DATE('.$ctx->dateColumn.')';

        // Группировка по day, чтобы кросс-DB (SQLite + MySQL) — потом сворачиваем в месяцы в PHP.
        switch ($dimension) {
            case 'brand':
                $rawRows = $base
                    ->leftJoin('products as products_brand', 'products_brand.id', '=', 'shipment_items.product_id')
                    ->selectRaw("
                        COALESCE(NULLIF(shipment_items.brand_name_snapshot, ''), 'Без бренда') AS dim_key,
                        MAX(products_brand.brand_id) AS dim_extra_id,
                        {$dateExpr} AS day,
                        SUM(shipment_items.total * COALESCE(currencies.exchange_rate, 1)) AS total_in_rub,
                        SUM(shipment_items.quantity) AS qty
                    ")
                    ->groupBy('dim_key', 'day')
                    ->get();
                break;

            case 'category':
                $rawRows = $base
                    ->leftJoin('products', 'products.id', '=', 'shipment_items.product_id')
                    ->selectRaw("
                        products.category_id AS dim_key,
                        {$dateExpr} AS day,
                        SUM(shipment_items.total * COALESCE(currencies.exchange_rate, 1)) AS total_in_rub,
                        SUM(shipment_items.quantity) AS qty
                    ")
                    ->groupBy('products.category_id', 'day')
                    ->get();
                break;

            case 'product':
                $rawRows = $base
                    ->leftJoin('products', 'products.id', '=', 'shipment_items.product_id')
                    ->selectRaw("
                        shipment_items.product_id AS dim_key,
                        COALESCE(NULLIF(shipment_items.product_name_snapshot, ''), MAX(products.name)) AS dim_label,
                        MAX(products.sku) AS sku,
                        MAX(products.slug) AS slug,
                        {$dateExpr} AS day,
                        SUM(shipment_items.total * COALESCE(currencies.exchange_rate, 1)) AS total_in_rub,
                        SUM(shipment_items.quantity) AS qty
                    ")
                    ->groupBy('shipment_items.product_id', 'shipment_items.product_name_snapshot', 'day')
                    ->get();
                break;

            default:
                return [];
        }

        // Свернём по объектам и переведём день -> месяц
        $byKey = [];
        foreach ($rawRows as $r) {
            $key = $r->dim_key !== null ? (string) $r->dim_key : '__none__';
            $monthKey = $r->day ? CarbonImmutable::parse($r->day)->format('Y-m') : null;
            if (! $monthKey) {
                continue;
            }
            $amount = $this->convertFromRub((float) $r->total_in_rub, $ctx);
            $qty = (int) $r->qty;

            if (! isset($byKey[$key])) {
                $byKey[$key] = [
                    'key' => $key,
                    'label' => null,
                    'sku' => $r->sku ?? null,
                    'slug' => $r->slug ?? null,
                    'extra_id' => isset($r->dim_extra_id) ? (int) $r->dim_extra_id : null,
                    'monthly' => [],
                    'total_amount' => 0.0,
                    'total_qty' => 0,
                ];
            }
            $byKey[$key]['label'] = $byKey[$key]['label']
                ?? ($r->dim_label ?? null);
            $byKey[$key]['monthly'][$monthKey] = ($byKey[$key]['monthly'][$monthKey] ?? 0) + $amount;
            $byKey[$key]['total_amount'] += $amount;
            $byKey[$key]['total_qty'] += $qty;
        }

        // Доформируем человекочитаемые label-ы
        if ($dimension === 'category') {
            $catIds = array_values(array_filter(array_map(
                fn ($k) => is_numeric($k) ? (int) $k : null,
                array_keys($byKey)
            )));
            $paths = $this->buildCategoryPaths($catIds);
            foreach ($byKey as $key => &$row) {
                $row['label'] = is_numeric($key)
                    ? ($paths[(int) $key] ?? "Категория #{$key}")
                    : 'Без категории';
            }
            unset($row);
        } elseif ($dimension === 'brand') {
            foreach ($byKey as $key => &$row) {
                $row['label'] = $key;
            }
            unset($row);
        } else { // product
            foreach ($byKey as $key => &$row) {
                if (! $row['label']) {
                    $row['label'] = $key === '__none__' ? 'Товар не указан' : "Товар #{$key}";
                }
            }
            unset($row);
        }

        return array_values(array_map(fn ($r) => [
            'key' => $r['key'],
            'label' => $r['label'],
            'sku' => $r['sku'] ?? null,
            'slug' => $r['slug'] ?? null,
            'extra_id' => $r['extra_id'] ?? null,
            'total_amount' => round($r['total_amount'], 2),
            'total_qty' => (int) $r['total_qty'],
            'monthly' => $r['monthly'],
        ], $byKey));
    }

    /**
     * Стратегия (рекомендация) для ячейки матрицы ABC/XYZ.
     */
    private function abcXyzStrategy(string $cell): string
    {
        return match ($cell) {
            'AX' => 'Главные стабильные позиции — нельзя допускать out-of-stock',
            'AY' => 'Высокий оборот, умеренные колебания — держать с запасом',
            'AZ' => 'Высокий оборот, нестабильный спрос — буферный запас и анализ причин',
            'BX' => 'Стабильно средние — поддерживать ассортимент',
            'BY' => 'Средний оборот с колебаниями — следить за остатками',
            'BZ' => 'Средний оборот, скачки — пересмотреть закупки',
            'CX' => 'Малый оборот, стабильно — можно автоматизировать поставки',
            'CY' => 'Малый оборот, нестабильно — точечные закупки',
            'CZ' => 'Малый оборот, непредсказуемо — кандидаты на вывод',
            default => '',
        };
    }

    /**
     * Простые человекочитаемые инсайты «что заказать» для тех, кто не хочет вникать в графики.
     *
     * @return array<string, mixed>
     */
    public function insights(AnalyticsContext $ctx, AnalyticsFilters $filters): array
    {
        return [
            'pareto' => $this->paretoProducts($ctx, $filters),
            'rising' => $this->trendBrands($ctx, $filters, 'up'),
            'falling' => $this->trendBrands($ctx, $filters, 'down'),
            'reorder' => $this->reorderSuggestions($ctx, $filters),
        ];
    }

    /**
     * Товары, формирующие 80% выручки за период (правило Парето).
     *
     * @return array<int, array<string, mixed>>
     */
    private function paretoProducts(AnalyticsContext $ctx, AnalyticsFilters $filters): array
    {
        $products = $this->byProduct($ctx, $filters, 200);
        $total = $products->sum('amount');
        if ($total <= 0) {
            return [];
        }

        $threshold = $total * 0.8;
        $cumulative = 0;
        $picks = [];

        foreach ($products as $row) {
            $cumulative += (float) $row['amount'];
            $picks[] = [
                'label' => $row['label'],
                'sku' => $row['sku'] ?? null,
                'slug' => $row['slug'] ?? null,
                'product_id' => $row['product_id'] ?? null,
                'amount' => $row['amount'],
                'qty' => $row['qty'],
                'share' => $total > 0 ? round((float) $row['amount'] / $total * 100, 1) : 0,
            ];
            if ($cumulative >= $threshold) {
                break;
            }
        }

        return $picks;
    }

    /**
     * Бренды, у которых сумма за текущий период существенно выросла/упала
     * по сравнению с предыдущим периодом такой же длины.
     *
     * @param  'up'|'down'  $direction
     * @return array<int, array<string, mixed>>
     */
    private function trendBrands(AnalyticsContext $ctx, AnalyticsFilters $filters, string $direction, int $limit = 5): array
    {
        $current = $this->byBrand($ctx, $filters, 100)->keyBy('label');

        $prevFilters = $filters->previousPeriod();
        $previous = $this->byBrand($ctx, $prevFilters, 100)->keyBy('label');

        $totalCurrent = (float) $current->sum('amount');
        $minBaseline = max(1.0, $totalCurrent * 0.005); // отсекаем шум: бренд должен давать ≥0.5% оборота

        $rows = [];
        foreach ($current as $label => $row) {
            if ($label === 'Без бренда') {
                continue;
            }
            $now = (float) $row['amount'];
            $prev = (float) ($previous[$label]['amount'] ?? 0);

            // отсекаем мелочь, чтобы «было 100, стало 1000» не давало +900%
            if ($direction === 'up' && $now < $minBaseline) {
                continue;
            }
            if ($direction === 'down' && $prev < $minBaseline) {
                continue;
            }

            $delta = $now - $prev;
            $deltaPct = $prev > 0 ? ($delta / $prev) * 100 : ($now > 0 ? 100 : 0);

            // Если в предыдущем периоде объём ничтожен (< 5% от текущего) —
            // это не «рост», а появление нового бренда в обороте.
            $isNew = $direction === 'up' && $prev < $now * 0.05;

            $rows[] = [
                'label' => $label,
                'brand_id' => $row['brand_id'] ?? null,
                'amount' => round($now, 2),
                'previous' => round($prev, 2),
                'delta' => round($delta, 2),
                'delta_pct' => round($deltaPct, 1),
                'is_new' => $isNew,
            ];
        }

        $rows = array_filter($rows, fn ($r) => $direction === 'up'
            ? ($r['is_new'] || $r['delta_pct'] > 10)
            : $r['delta_pct'] < -10);

        // Для роста: сначала бренды с реальным % приростом, потом новинки;
        // ранжируем по абсолютной дельте (a не по %), чтобы наверх не выскакивали микро-новинки.
        usort($rows, fn ($a, $b) => $direction === 'up'
            ? $b['delta'] <=> $a['delta']
            : $a['delta'] <=> $b['delta']);

        return array_slice($rows, 0, $limit);
    }

    /**
     * Товары, которые покупаются регулярно, и от последней отгрузки прошло
     * больше среднего интервала — пора повторить заказ.
     *
     * @return array<int, array<string, mixed>>
     */
    private function reorderSuggestions(AnalyticsContext $ctx, AnalyticsFilters $filters, int $limit = 10): array
    {
        if ($ctx->isEmpty()) {
            return [];
        }

        // Анализируем годовой горизонт независимо от выбранного периода —
        // блок про общую регулярность закупа, а не про текущий месяц.
        $since = CarbonImmutable::now()->subYear()->toDateString();

        $rows = DB::table('shipments')
            ->join('shipment_items', 'shipment_items.shipment_id', '=', 'shipments.id')
            ->leftJoin('products', 'products.id', '=', 'shipment_items.product_id')
            ->whereNull('shipments.deleted_at')
            ->whereIn('shipments.user_id', $ctx->userIds)
            ->where($ctx->dateColumn, '>=', $since)
            ->whereNotNull('shipment_items.product_id')
            ->selectRaw('
                shipment_items.product_id              AS product_id,
                MAX(products.name)                     AS product_name,
                MAX(products.sku)                      AS sku,
                MAX(products.slug)                     AS slug,
                MIN('.$ctx->dateColumn.')              AS first_date,
                MAX('.$ctx->dateColumn.')              AS last_date,
                COUNT(DISTINCT shipments.id)           AS shipments_count,
                SUM(shipment_items.quantity)           AS total_qty
            ')
            ->groupBy('shipment_items.product_id')
            ->having('shipments_count', '>=', 3)
            ->get();

        $today = CarbonImmutable::now()->startOfDay();
        $candidates = [];

        foreach ($rows as $r) {
            if (! $r->first_date || ! $r->last_date) {
                continue;
            }
            $first = CarbonImmutable::parse($r->first_date);
            $last = CarbonImmutable::parse($r->last_date);
            $daysSpan = max(1, (int) $first->diffInDays($last));
            $avgInterval = (int) round($daysSpan / max(1, $r->shipments_count - 1));
            $daysSinceLast = max(0, (int) $last->diffInDays($today));

            if ($avgInterval < 5 || $avgInterval > 180) {
                continue; // отсеиваем экзотику
            }
            if ($daysSinceLast < $avgInterval) {
                continue; // ещё не пора
            }

            $overdue = $daysSinceLast - $avgInterval;
            $avgQty = (int) max(1, round($r->total_qty / $r->shipments_count));

            $candidates[] = [
                'label' => $r->product_name ?? 'Товар не указан',
                'sku' => $r->sku,
                'slug' => $r->slug ?? null,
                'product_id' => (int) $r->product_id,
                'shipments_count' => (int) $r->shipments_count,
                'avg_interval_days' => $avgInterval,
                'days_since_last' => $daysSinceLast,
                'overdue_days' => $overdue,
                'avg_qty' => $avgQty,
            ];
        }

        usort($candidates, fn ($a, $b) => $b['overdue_days'] <=> $a['overdue_days']);

        return array_slice($candidates, 0, $limit);
    }
}
