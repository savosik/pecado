<?php

namespace App\Http\Controllers\Crm;

use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Analytics\AnalyticsContext;
use App\Services\Analytics\AnalyticsFilters;
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
            'initial' => $this->buildPayload($ctx, $filters, $seesAll, $this->wantsComparison($request)),
            'filterOptions' => $this->filterOptions($ctx, $seesAll),
            'seesAll' => $seesAll,
        ]);
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
            $this->buildPayload($ctx, $filters, $seesAll, $this->wantsComparison($request))
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
            'Бренд' => $this->analytics->byBrand($ctx, $filters),
            'Категория' => $this->analytics->byCategory($ctx, $filters),
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
    private function buildPayload(AnalyticsContext $ctx, AnalyticsFilters $filters, bool $seesAll, bool $compare): array
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
            'by_contractor' => $this->analytics->byContractor($ctx, $filters),
            'by_product' => $this->analytics->byProduct($ctx, $filters),
            'by_manager' => $seesAll ? $this->analytics->byManager($ctx, $filters) : [],
            'comparison' => null,
        ];

        if ($compare) {
            $prevFilters = $filters->previousPeriod();
            $prevMetrics = $this->analytics->metrics($ctx, $prevFilters);

            $payload['comparison'] = [
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

    private function wantsComparison(Request $request): bool
    {
        return filter_var($request->input('compare', false), FILTER_VALIDATE_BOOLEAN);
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
