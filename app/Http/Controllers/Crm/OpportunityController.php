<?php

namespace App\Http\Controllers\Crm;

use App\Enums\Crm\OpportunityPreset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\Analytics\AnalyticsContext;
use App\Services\Analytics\ShipmentAnalyticsService;
use App\Services\Crm\OpportunityService;
use App\Services\Crm\PlanScope;
use App\Services\Crm\PlanScopeResolver;
use App\Services\Crm\SalesPlanService;
use App\Services\SimpleXlsxExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Возможности: кому продать, кто не брал, кто просел.
 *
 * Витрина поверх уже существующих расчётов — своей таблицы у возможностей нет.
 * Отбор и ранжирование живут в {@see OpportunityService}, скоуп и его границы —
 * в {@see PlanScopeResolver}, тех же, что у выполнения планов: два экрана про
 * один и тот же отдел обязаны показывать одну и ту же базу.
 */
class OpportunityController extends CrmController
{
    /** Измерения, по которым спрашивают «а это они не берут?». */
    private const DIMENSIONS = ['brand', 'category', 'product'];

    public function __construct(
        private readonly OpportunityService $opportunities,
        private readonly PlanScopeResolver $scopes,
        private readonly SalesPlanService $plans,
        private readonly ShipmentAnalyticsService $analytics,
    ) {}

    /**
     * Страница раздела. GET /crm/opportunities
     */
    public function index(Request $request): Response
    {
        $month = $this->plans->parseMonth($request->string('month')->value());
        $actor = $this->crmActor($request);

        return Inertia::render('Crm/Pages/Opportunities/Index', [
            'month' => $month->format('Y-m'),
            'monthLabel' => $this->plans->monthLabel($month),
            'presets' => OpportunityPreset::options(),
            'scopeOptions' => $this->scopes->options($actor),
            'canSeeAll' => $this->seesDepartment($request),
        ]);
    }

    /**
     * Ранжированный список по пресету. GET /crm/opportunities/data
     *
     * Отдельным JSON-адресом, а не в payload страницы: тот же список встраивается
     * вкладкой в «Планы продаж», и пересобирать его через полный визит Inertia
     * значило бы терять выбранный месяц и скоуп соседней вкладки.
     */
    public function data(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);
        $month = $this->plans->parseMonth($request->string('month')->value());
        $scope = $this->resolveScope($request);
        $preset = $this->resolvePreset($request);
        $params = $this->presetParams($request);

        $result = $this->opportunities->rank($month, $scope, $preset, $params);

        return response()->json($result + [
            'month' => $month->format('Y-m'),
            'monthLabel' => $this->plans->monthLabel($month),
            'scope' => $this->scopes->payload($scope),
            'scopeOptions' => $this->scopes->options($actor),
            'presets' => OpportunityPreset::options(),
            'params' => $params,
            'canSeeAll' => $this->seesDepartment($request),
        ]);
    }

    /**
     * Бренды и категории скоупа для пресета «не берут X».
     * GET /crm/opportunities/dimensions
     *
     * Отдельным запросом: список нужен одному пресету из пяти, а собирается
     * тяжёлым DISTINCT по отгрузкам всего отдела.
     */
    public function dimensions(Request $request): JsonResponse
    {
        $scope = $this->resolveScope($request);

        if ($scope->isEmpty()) {
            return response()->json(['brands' => [], 'categories' => []]);
        }

        $options = $this->analytics->filterOptionsForScope(
            AnalyticsContext::forScope($scope->clientIds, AnalyticsContext::DATE_ERP, null),
        );

        return response()->json([
            'brands' => $options['brands'],
            'categories' => $options['categories'],
        ]);
    }

    /**
     * XLSX текущего списка. GET /crm/opportunities/export
     */
    public function export(Request $request, SimpleXlsxExporter $exporter): StreamedResponse
    {
        $month = $this->plans->parseMonth($request->string('month')->value());
        $scope = $this->resolveScope($request);
        $preset = $this->resolvePreset($request);

        $result = $this->opportunities->rank($month, $scope, $preset, $this->presetParams($request));

        $headers = [
            'Партнёр', 'Менеджер', 'Причина', 'Оценка',
            'План, ₽', 'Факт, ₽', 'Недобор, ₽', 'Прошлый месяц, ₽',
            'Последняя отгрузка', 'Дней без покупок', 'Средний чек, ₽', 'Класс',
        ];

        $rows = [];

        foreach ($result['rows'] as $row) {
            $rows[] = [
                $row['name'],
                $row['manager'] ?? '—',
                $row['explanation'],
                $row['score'],
                $row['plan'] ?? '',
                $row['fact'],
                $row['lag'] ?? '',
                $row['previous_amount'],
                $row['last_purchase_at'] ?? '—',
                $row['days_since'] ?? '',
                $row['avg_check'] ?? '',
                $row['abc'] ?? '—',
            ];
        }

        return $exporter->stream(
            'crm-opportunities-'.$preset->value.'-'.$month->format('Y-m'),
            $headers,
            $rows,
            'Возможности',
        );
    }

    private function resolveScope(Request $request): PlanScope
    {
        return $this->scopes->resolve(
            $this->crmActor($request),
            $request->string('scope')->value() ?: null,
            (int) $request->input('scope_id', 0) ?: null,
        );
    }

    /**
     * Неизвестный пресет — «отстают от плана», а не ошибка валидации: адрес
     * с пресетом сохраняют в закладки, и переименование не должно ронять экран.
     */
    private function resolvePreset(Request $request): OpportunityPreset
    {
        return OpportunityPreset::tryFrom((string) $request->input('preset'))
            ?? OpportunityPreset::PLAN_LAG;
    }

    /**
     * Параметры пресета «не берут X»: измерение, его значение и подпись.
     *
     * Подпись резолвится по справочнику, а не берётся из запроса: в объяснение
     * строки должно попасть название из базы, а не то, что прислал партнёр.
     *
     * @return array{dimension: string|null, value: int|null, label: string|null}
     */
    private function presetParams(Request $request): array
    {
        $dimension = (string) $request->input('dimension');
        $value = (int) $request->input('value', 0);

        if (! in_array($dimension, self::DIMENSIONS, true) || $value <= 0) {
            return ['dimension' => null, 'value' => null, 'label' => null];
        }

        $name = match ($dimension) {
            'brand' => Brand::query()->whereKey($value)->value('name'),
            'category' => Category::query()->whereKey($value)->value('name'),
            default => Product::query()->whereKey($value)->value('name'),
        };

        if ($name === null) {
            return ['dimension' => null, 'value' => null, 'label' => null];
        }

        $prefix = match ($dimension) {
            'brand' => 'бренд',
            'category' => 'категория',
            default => 'товар',
        };

        return [
            'dimension' => $dimension,
            'value' => $value,
            'label' => $prefix.' «'.$name.'»',
        ];
    }
}
