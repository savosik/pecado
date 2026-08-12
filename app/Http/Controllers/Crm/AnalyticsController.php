<?php

namespace App\Http\Controllers\Crm;

use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\User;
use App\Services\Analytics\AnalyticsContext;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\GapAnalysisService;
use App\Services\Analytics\ShipmentAnalyticsService;
use App\Services\SimpleXlsxExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Интерактивный отчёт продаж отдела (CRM).
 *
 * Изоляция данных: скоуп партнёров из User::visibleInCrm() —
 *  - охват данных задаёт crm-department.view, разрез по менеджерам — crm-clients-all.view;
 *  - менеджер — только своих партнёров, без разреза по менеджерам.
 *
 * Отличия от кабинетной аналитики: набор партнёров вместо одного пользователя,
 * бизнес-дата = дата документа 1С (erp_created_at), суммы в рублях,
 * плюс сравнение с предыдущим периодом и разрез по менеджерам.
 */
class AnalyticsController extends CrmController
{
    public function __construct(
        private readonly ShipmentAnalyticsService $analytics,
        private readonly GapAnalysisService $gap,
    ) {}

    /**
     * Страница отчёта. GET /crm/analytics
     */
    public function index(Request $request): InertiaResponse
    {
        $actor = $this->crmActor($request);
        $seesAll = $this->seesManagerBreakdown($request);
        $ctx = $this->resolveContext($request, $actor, $this->seesDepartment($request), $seesAll);
        $filters = AnalyticsFilters::fromScopeRequest($request);

        return Inertia::render('Crm/Pages/Analytics/Index', [
            'initial' => $this->buildPayload($ctx, $filters, $seesAll, ...$this->resolveComparison($request)),
            'filterOptions' => $this->filterOptions($ctx, $seesAll),
            'seesAll' => $seesAll,
            'presets' => $this->presetList($actor),
        ]);
    }

    /**
     * Личные пресеты фильтров сотрудника, новые сверху.
     *
     * @return array<int, array{id: int, name: string, payload: array<string, mixed>}>
     */
    private function presetList(User $actor): array
    {
        return $actor->crmAnalyticsFilterPresets()
            ->latest()
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'payload' => $p->payload ?? [],
            ])
            ->all();
    }

    /**
     * Сохранить текущий набор фильтров как личный пресет. POST /crm/analytics/presets
     */
    public function storePreset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'payload' => ['required', 'array'],
        ]);

        $actor = $this->crmActor($request);

        $preset = $actor->crmAnalyticsFilterPresets()->create([
            'name' => trim($data['name']),
            'payload' => $data['payload'],
        ]);

        return response()->json([
            'id' => $preset->id,
            'name' => $preset->name,
            'payload' => $preset->payload ?? [],
        ], 201);
    }

    /**
     * Удалить личный пресет. DELETE /crm/analytics/presets/{preset}
     *
     * Пресет ищем в рамках владельца, чтобы чужой отдавал 404, а не 403.
     */
    public function destroyPreset(Request $request, int $preset): JsonResponse
    {
        $this->crmActor($request)
            ->crmAnalyticsFilterPresets()
            ->findOrFail($preset)
            ->delete();

        return response()->json(null, 204);
    }

    /**
     * JSON для XHR-обновления при смене фильтров. GET /crm/analytics/data
     */
    public function data(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);
        $seesAll = $this->seesManagerBreakdown($request);
        $ctx = $this->resolveContext($request, $actor, $this->seesDepartment($request), $seesAll);
        $filters = AnalyticsFilters::fromScopeRequest($request);

        return response()->json(
            $this->buildPayload($ctx, $filters, $seesAll, ...$this->resolveComparison($request))
        );
    }

    /**
     * ABC/XYZ-анализ за 12 месяцев. GET /crm/analytics/abc-xyz?dimension=brand|category|product
     */
    public function abcXyz(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);
        $seesAll = $this->seesManagerBreakdown($request);
        $ctx = $this->resolveContext($request, $actor, $this->seesDepartment($request), $seesAll);

        $dimension = (string) $request->input('dimension', 'brand');
        if (! in_array($dimension, ['brand', 'category', 'product'], true)) {
            $dimension = 'brand';
        }

        $filters = AnalyticsFilters::fromScopeRequest($request);

        return response()->json($this->analytics->abcXyz($ctx, $dimension, $filters));
    }

    /**
     * Gap-анализ (кросс-продажи): партнёры/контрагенты без покупок бренда/
     * категории/товара. GET /crm/analytics/gap
     */
    public function gap(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);
        $seesAll = $this->seesManagerBreakdown($request);
        $ctx = $this->resolveContext($request, $actor, $this->seesDepartment($request), $seesAll);
        $filters = AnalyticsFilters::fromScopeRequest($request);

        return response()->json(
            $this->gap->analyze($ctx, $this->gapParams($request), $filters->dateFrom, $filters->dateTo)
        );
    }

    /**
     * XLSX-выгрузка gap-анализа по текущим условиям. GET /crm/analytics/gap/export
     */
    public function gapExport(Request $request, SimpleXlsxExporter $exporter): StreamedResponse
    {
        $actor = $this->crmActor($request);
        $seesAll = $this->seesManagerBreakdown($request);
        $ctx = $this->resolveContext($request, $actor, $this->seesDepartment($request), $seesAll);
        $filters = AnalyticsFilters::fromScopeRequest($request);

        $result = $this->gap->analyze($ctx, $this->gapParams($request), $filters->dateFrom, $filters->dateTo);
        $isPartner = ($result['subject'] ?? 'partner') === 'partner';

        $headers = [$isPartner ? 'Партнёр' : 'Контрагент', 'Менеджер', 'Оборот за период, ₽', 'Поставок за период', 'Последняя покупка'];
        $rows = [];
        foreach ($result['rows'] as $row) {
            $rows[] = [
                $row['label'] ?? '',
                $row['manager'] ?? '—',
                round((float) ($row['amount'] ?? 0), 2),
                (int) ($row['shipments_count'] ?? 0),
                $row['last_purchase_at'] ?? '—',
            ];
        }

        return $exporter->stream('crm-gap-'.now()->format('Y-m-d-His'), $headers, $rows, 'Возможности');
    }

    /**
     * Валидирует и нормализует параметры gap-анализа из запроса.
     *
     * @return array{
     *   subject: string, exclude_dimension: string, exclude_value: int,
     *   exclude_window: string, exclude_months: int,
     *   include_dimension: ?string, include_value: ?int, include_dormant: bool
     * }
     */
    private function gapParams(Request $request): array
    {
        $dimensions = ['brand', 'category', 'product'];

        $subject = (string) $request->input('subject', 'partner');
        if (! in_array($subject, ['partner', 'contractor'], true)) {
            $subject = 'partner';
        }

        $excludeDimension = (string) $request->input('exclude_dimension', 'brand');
        if (! in_array($excludeDimension, $dimensions, true)) {
            $excludeDimension = 'brand';
        }

        $excludeWindow = (string) $request->input('exclude_window', 'all');
        if (! in_array($excludeWindow, ['all', 'months', 'period'], true)) {
            $excludeWindow = 'all';
        }

        $includeDimension = $request->input('include_dimension');
        $includeDimension = in_array($includeDimension, $dimensions, true) ? $includeDimension : null;

        return [
            'subject' => $subject,
            'exclude_dimension' => $excludeDimension,
            'exclude_value' => max(0, (int) $request->input('exclude_value', 0)),
            'exclude_window' => $excludeWindow,
            'exclude_months' => max(1, min(60, (int) $request->input('exclude_months', 6))),
            'include_dimension' => $includeDimension,
            'include_value' => $includeDimension !== null ? max(0, (int) $request->input('include_value', 0)) : null,
            'include_dormant' => filter_var($request->input('include_dormant', false), FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * XLSX-выгрузка отчёта по текущим фильтрам. GET /crm/analytics/export
     *
     * Разрезы берутся **без лимита** (в отличие от экрана, где строки режутся
     * потолком UI) — выгрузка должна давать полную картину, а не топ-N.
     * Каждый разрез уходит на свой лист со своими колонками; первый лист —
     * «Итоги» с периодом, KPI и количеством строк по разрезам.
     */
    public function export(Request $request, SimpleXlsxExporter $exporter): StreamedResponse
    {
        $actor = $this->crmActor($request);
        $seesAll = $this->seesManagerBreakdown($request);
        $ctx = $this->resolveContext($request, $actor, $this->seesDepartment($request), $seesAll);
        $filters = AnalyticsFilters::fromScopeRequest($request);

        $sections = $this->exportSections($ctx, $filters, $seesAll);

        $sheets = [$this->summarySheet($ctx, $filters, $sections)];

        foreach ($sections as $section) {
            $sheets[] = [
                'title' => $section['title'],
                'headers' => $section['headers'],
                'rows' => $this->exportRows($section),
            ];
        }

        return $exporter->streamSheets('crm-analytics-'.now()->format('Y-m-d-His'), $sheets);
    }

    /**
     * Описание листов-разрезов: заголовки колонок, строки и маппер.
     *
     * @return array<string, array{title: string, headers: list<string>, items: \Illuminate\Support\Collection<int, array<string, mixed>>, mapper: callable}>
     */
    private function exportSections(AnalyticsContext $ctx, AnalyticsFilters $filters, bool $seesAll): array
    {
        $base = ['Значение', 'Поставок', 'Контрагентов', 'Штук', 'Сумма, ₽', 'Доля, %'];

        $sections = [];

        if ($seesAll) {
            $sections['managers'] = [
                'title' => 'Менеджеры',
                'headers' => ['Менеджер', 'Партнёров', 'Поставок', 'Контрагентов', 'Штук', 'Сумма, ₽', 'Доля, %'],
                'items' => $this->analytics->byManager($ctx, $filters, null),
                'mapper' => fn (array $r) => [
                    $r['label'] ?? '',
                    (int) ($r['clients_count'] ?? 0),
                    (int) ($r['shipments_count'] ?? 0),
                    (int) ($r['contractors_count'] ?? 0),
                    (int) ($r['qty'] ?? 0),
                    round((float) ($r['amount'] ?? 0), 2),
                ],
            ];
        }

        $sections['organizations'] = [
            'title' => 'Организации',
            'headers' => $base,
            'items' => $this->analytics->byOrganization($ctx, $filters, null),
            'mapper' => $this->baseMapper(),
        ];

        $sections['warehouses'] = [
            'title' => 'Склады отгрузки',
            'headers' => $base,
            'items' => $this->analytics->byWarehouse($ctx, $filters, null),
            'mapper' => $this->baseMapper(),
        ];

        $sections['brands'] = [
            'title' => 'Бренды',
            'headers' => $base,
            'items' => $this->analytics->byBrand($ctx, $filters, null),
            'mapper' => $this->baseMapper(),
        ];

        $sections['categories'] = [
            'title' => 'Категории',
            'headers' => $base,
            'items' => $this->analytics->byCategory($ctx, $filters, null),
            'mapper' => $this->baseMapper(),
        ];

        $sections['partners'] = [
            'title' => 'Партнёры',
            'headers' => $base,
            'items' => $this->analytics->byPartner($ctx, $filters, null),
            'mapper' => $this->baseMapper(),
        ];

        $sections['contractors'] = [
            'title' => 'Контрагенты',
            'headers' => ['Контрагент', 'ИНН', 'Поставок', 'Штук', 'Сумма, ₽', 'Доля, %'],
            'items' => $this->analytics->byContractor($ctx, $filters, null),
            'mapper' => fn (array $r) => [
                $r['label'] ?? '',
                (string) ($r['tax_id'] ?? ''),
                (int) ($r['shipments_count'] ?? 0),
                (int) ($r['qty'] ?? 0),
                round((float) ($r['amount'] ?? 0), 2),
            ],
        ];

        $sections['products'] = [
            'title' => 'Товары',
            'headers' => ['Товар', 'Артикул', 'Поставок', 'Контрагентов', 'Штук', 'Сумма, ₽', 'Доля, %'],
            'items' => $this->analytics->byProduct($ctx, $filters, null),
            'mapper' => fn (array $r) => [
                $r['label'] ?? '',
                (string) ($r['sku'] ?? ''),
                (int) ($r['shipments_count'] ?? 0),
                (int) ($r['contractors_count'] ?? 0),
                (int) ($r['qty'] ?? 0),
                round((float) ($r['amount'] ?? 0), 2),
            ],
        ];

        return $sections;
    }

    /**
     * Колонки, общие для большинства разрезов.
     */
    private function baseMapper(): callable
    {
        return fn (array $r) => [
            $r['label'] ?? '',
            (int) ($r['shipments_count'] ?? 0),
            (int) ($r['contractors_count'] ?? 0),
            (int) ($r['qty'] ?? 0),
            round((float) ($r['amount'] ?? 0), 2),
        ];
    }

    /**
     * Строки листа: маппер + доля строки в сумме разреза последней колонкой.
     *
     * @param  array{items: \Illuminate\Support\Collection<int, array<string, mixed>>, mapper: callable}  $section
     * @return list<array<int, scalar|null>>
     */
    private function exportRows(array $section): array
    {
        $total = (float) $section['items']->sum(fn (array $r) => (float) ($r['amount'] ?? 0));

        return $section['items']
            ->map(function (array $row) use ($section, $total) {
                $cells = ($section['mapper'])($row);
                $cells[] = $total > 0 ? round((float) ($row['amount'] ?? 0) / $total * 100, 2) : 0.0;

                return $cells;
            })
            ->all();
    }

    /**
     * Лист «Итоги»: период, KPI и число строк в каждом разрезе —
     * чтобы по файлу было видно, что выгрузка полная.
     *
     * @param  array<string, array{title: string, items: \Illuminate\Support\Collection<int, array<string, mixed>>}>  $sections
     * @return array{title: string, headers: list<string>, rows: list<array<int, scalar|null>>}
     */
    private function summarySheet(AnalyticsContext $ctx, AnalyticsFilters $filters, array $sections): array
    {
        $metrics = $this->analytics->metrics($ctx, $filters);

        $rows = [
            ['Период с', $filters->dateFrom->toDateString()],
            ['Период по', $filters->dateTo->toDateString()],
            ['Выгружено', now()->format('d.m.Y H:i')],
            ['Поставок', $metrics['shipments_count'] ?? 0],
            ['Сумма, ₽', $metrics['total_amount'] ?? 0],
            ['Средний чек, ₽', $metrics['avg_check'] ?? 0],
            ['Штук', $metrics['items_total_qty'] ?? 0],
            ['Контрагентов', $metrics['contractors_count'] ?? 0],
        ];

        foreach ($sections as $section) {
            $rows[] = ['Строк в разрезе «'.$section['title'].'»', $section['items']->count()];
        }

        return [
            'title' => 'Итоги',
            'headers' => ['Показатель', 'Значение'],
            'rows' => $rows,
        ];
    }

    /**
     * Поиск товаров для фильтра отчёта (CRM-доступный аналог admin.products.search).
     * GET /crm/products/search?query=...
     */
    public function searchProducts(Request $request): JsonResponse
    {
        $query = trim((string) $request->input('query', ''));

        if ($query === '') {
            return response()->json([]);
        }

        $products = Product::search($query)
            ->query(fn ($q) => $q->with(['media', 'brand'])->limit(20))
            ->get()
            ->map(fn ($product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'image_url' => $product->getFirstMediaUrl('main'),
                'price' => $product->base_price,
                'barcode' => $product->barcode,
                'brand_name' => $product->brand?->name,
            ]);

        return response()->json($products);
    }

    /**
     * Собирает контекст выполнения: набор id партнёров в скоупе + бизнес-дата 1С + рубли.
     * Для РОП применяет опциональный фильтр по менеджерам (manager_ids).
     */
    private function resolveContext(Request $request, User $actor, bool $seesDepartment, bool $seesBreakdown): AnalyticsContext
    {
        $query = User::query()->visibleInCrm($actor);

        // Отбор по менеджерам сужает уже видимое. Тому, кто отдел не видит,
        // он бесполезен (скоуп отсечёт раньше), поэтому и не предлагается.
        if ($seesDepartment && $seesBreakdown) {
            $managerIds = $this->sanitizeIds($request->input('manager_ids', []));
            if ($managerIds !== []) {
                $query->whereIn('personal_manager_id', $managerIds);
            }
        }

        $userIds = $query->pluck('id')->all();

        return AnalyticsContext::forScope($userIds, AnalyticsContext::DATE_ERP, null);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(AnalyticsContext $ctx, AnalyticsFilters $filters, bool $seesAll, string $compareMode, int $compareOffset): array
    {
        $metrics = $this->analytics->metrics($ctx, $filters);
        $timeSeries = $this->analytics->timeSeries($ctx, $filters);

        // Разрезы тянем с одной «запасной» строкой сверх лимита: так видно,
        // что данные на экране обрезаны, и можно честно предложить XLSX.
        $limit = ShipmentAnalyticsService::UI_LIMIT_DEFAULT;
        $productLimit = ShipmentAnalyticsService::UI_LIMIT_PRODUCTS;

        $breakdowns = [
            'by_brand' => ShipmentAnalyticsService::cap($this->analytics->byBrand($ctx, $filters, $limit + 1), $limit),
            'by_category' => ShipmentAnalyticsService::cap($this->analytics->byCategory($ctx, $filters, $limit + 1), $limit),
            'by_partner' => ShipmentAnalyticsService::cap($this->analytics->byPartner($ctx, $filters, $limit + 1), $limit),
            'by_contractor' => ShipmentAnalyticsService::cap($this->analytics->byContractor($ctx, $filters, $limit + 1), $limit),
            'by_product' => ShipmentAnalyticsService::cap($this->analytics->byProduct($ctx, $filters, $productLimit + 1), $productLimit),
            'by_manager' => $seesAll
                ? ShipmentAnalyticsService::cap($this->analytics->byManager($ctx, $filters, $limit + 1), $limit)
                : ['rows' => [], 'truncated' => false, 'limit' => $limit],
            // v15.8.0: разрезы по нашим юрлицам и складам отгрузки
            'by_organization' => ShipmentAnalyticsService::cap($this->analytics->byOrganization($ctx, $filters, $limit + 1), $limit),
            'by_warehouse' => ShipmentAnalyticsService::cap($this->analytics->byWarehouse($ctx, $filters, $limit + 1), $limit),
        ];

        $payload = [
            'filters' => $filters->toArray(),
            'currency' => ['code' => 'RUB', 'symbol' => '₽'],
            'metrics' => $metrics,
            'insights' => $this->analytics->insights($ctx, $filters),
            'time_series' => $timeSeries,
            'comparison' => null,
            // Признак «показаны не все строки» по каждому разрезу
            'truncation' => array_map(
                fn (array $b) => ['truncated' => $b['truncated'], 'limit' => $b['limit']],
                $breakdowns,
            ),
        ];

        foreach ($breakdowns as $key => $breakdown) {
            $payload[$key] = $breakdown['rows'];
        }

        $prevFilters = $filters->comparisonPeriod($compareMode, $compareOffset);

        if ($prevFilters !== null) {
            $prevMetrics = $this->analytics->metrics($ctx, $prevFilters);

            $payload['comparison'] = [
                'mode' => $compareMode,
                'offset' => $compareOffset,
                'period' => [
                    'date_from' => $prevFilters->dateFrom->toDateString(),
                    'date_to' => $prevFilters->dateTo->toDateString(),
                ],
                'metrics' => $prevMetrics,
                'deltas' => $this->deltas($metrics, $prevMetrics),
                'time_series' => $this->analytics->timeSeries($ctx, $prevFilters),
            ];
        }

        return $payload;
    }

    /**
     * Δ абсолютные и процентные по каждому KPI (current vs previous).
     *
     * @param  array<string, int|float>  $current
     * @param  array<string, int|float>  $previous
     * @return array<string, array{abs: float, pct: float|null}>
     */
    private function deltas(array $current, array $previous): array
    {
        $keys = ['shipments_count', 'total_amount', 'avg_check', 'items_total_qty', 'contractors_count'];
        $result = [];

        foreach ($keys as $key) {
            $now = (float) ($current[$key] ?? 0);
            $prev = (float) ($previous[$key] ?? 0);
            $abs = $now - $prev;
            $pct = $prev > 0 ? round($abs / $prev * 100, 1) : ($now > 0 ? null : 0.0);

            $result[$key] = ['abs' => round($abs, 2), 'pct' => $pct];
        }

        return $result;
    }

    /**
     * Опции фильтров: контрагенты/бренды/категории по скоупу + менеджеры (только РОП).
     *
     * @return array<string, mixed>
     */
    private function filterOptions(AnalyticsContext $ctx, bool $seesAll): array
    {
        $options = $this->analytics->filterOptionsForScope($ctx);

        $options['managers'] = $seesAll
            ? PersonalManager::query()
                ->active()
                ->whereHas('users')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($m) => ['id' => (int) $m->id, 'name' => (string) $m->name])
                ->all()
            : [];

        return $options;
    }

    /**
     * Режим и смещение базы сравнения из запроса.
     * Легаси-параметр compare=1 трактуется как предыдущий период.
     *
     * @return array{0: string, 1: int}
     */
    private function resolveComparison(Request $request): array
    {
        $mode = (string) $request->input('compare_mode', 'none');

        if (! in_array($mode, AnalyticsFilters::COMPARE_MODES, true)) {
            $mode = filter_var($request->input('compare', false), FILTER_VALIDATE_BOOLEAN)
                ? 'prev_period'
                : 'none';
        }

        $offset = (int) $request->input('compare_offset', 1);
        $offset = max(1, min(12, $offset));

        return [$mode, $offset];
    }

    /**
     * @return array<int, int>
     */
    private function sanitizeIds(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('intval', $input),
            fn (int $id) => $id > 0,
        )));
    }
}
