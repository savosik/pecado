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
 * Изоляция данных: скоуп клиентов из User::visibleInCrm() —
 *  - РОП (crm-clients-all.view) видит весь отдел и разрез/фильтр по менеджерам;
 *  - менеджер — только своих клиентов, без разреза по менеджерам.
 *
 * Отличия от кабинетной аналитики: набор клиентов вместо одного пользователя,
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
        $seesAll = $this->seesAllClients($request);
        $ctx = $this->resolveContext($request, $actor, $seesAll);
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
        $seesAll = $this->seesAllClients($request);
        $ctx = $this->resolveContext($request, $actor, $seesAll);
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
        $seesAll = $this->seesAllClients($request);
        $ctx = $this->resolveContext($request, $actor, $seesAll);

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
        $seesAll = $this->seesAllClients($request);
        $ctx = $this->resolveContext($request, $actor, $seesAll);
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
        $seesAll = $this->seesAllClients($request);
        $ctx = $this->resolveContext($request, $actor, $seesAll);
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
     */
    public function export(Request $request, SimpleXlsxExporter $exporter): StreamedResponse
    {
        $actor = $this->crmActor($request);
        $seesAll = $this->seesAllClients($request);
        $ctx = $this->resolveContext($request, $actor, $seesAll);
        $filters = AnalyticsFilters::fromScopeRequest($request);

        $headers = ['Группировка', 'Значение', 'Сумма, ₽', 'Штук', 'Поставок', 'Контрагентов'];
        $rows = [];

        $sections = [
            'Организация' => $this->analytics->byOrganization($ctx, $filters),
            'Склад отгрузки' => $this->analytics->byWarehouse($ctx, $filters),
            'Бренд' => $this->analytics->byBrand($ctx, $filters),
            'Категория' => $this->analytics->byCategory($ctx, $filters),
            'Партнёр' => $this->analytics->byPartner($ctx, $filters),
            'Контрагент' => $this->analytics->byContractor($ctx, $filters),
            'Товар' => $this->analytics->byProduct($ctx, $filters),
        ];
        if ($seesAll) {
            $sections = ['Менеджер' => $this->analytics->byManager($ctx, $filters)] + $sections;
        }

        foreach ($sections as $sectionLabel => $items) {
            foreach ($items as $item) {
                $rows[] = [
                    $sectionLabel,
                    $item['label'] ?? '',
                    round((float) ($item['amount'] ?? 0), 2),
                    (int) ($item['qty'] ?? 0),
                    (int) ($item['shipments_count'] ?? 0),
                    (int) ($item['contractors_count'] ?? 0),
                ];
            }
        }

        return $exporter->stream('crm-analytics-'.now()->format('Y-m-d-His'), $headers, $rows, 'Отчёт продаж');
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
     * Собирает контекст выполнения: набор id клиентов в скоупе + бизнес-дата 1С + рубли.
     * Для РОП применяет опциональный фильтр по менеджерам (manager_ids).
     */
    private function resolveContext(Request $request, User $actor, bool $seesAll): AnalyticsContext
    {
        $query = User::query()->visibleInCrm($actor);

        // Фильтр по менеджерам — только РОП; иначе менеджер подставил бы чужой id.
        if ($seesAll) {
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

        $payload = [
            'filters' => $filters->toArray(),
            'currency' => ['code' => 'RUB', 'symbol' => '₽'],
            'metrics' => $metrics,
            'insights' => $this->analytics->insights($ctx, $filters),
            'time_series' => $timeSeries,
            'by_brand' => $this->analytics->byBrand($ctx, $filters),
            'by_category' => $this->analytics->byCategory($ctx, $filters),
            'by_partner' => $this->analytics->byPartner($ctx, $filters),
            'by_contractor' => $this->analytics->byContractor($ctx, $filters),
            'by_product' => $this->analytics->byProduct($ctx, $filters),
            'by_manager' => $seesAll ? $this->analytics->byManager($ctx, $filters) : [],
            // v15.8.0: разрезы по нашим юрлицам и складам отгрузки
            'by_organization' => $this->analytics->byOrganization($ctx, $filters),
            'by_warehouse' => $this->analytics->byWarehouse($ctx, $filters),
            'comparison' => null,
        ];

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
