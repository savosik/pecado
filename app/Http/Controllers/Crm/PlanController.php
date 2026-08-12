<?php

namespace App\Http\Controllers\Crm;

use App\Enums\Crm\PlanTarget;
use App\Http\Requests\Crm\StoreSalesPlansRequest;
use App\Models\CrmSalesPlan;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Crm\PlanProgressService;
use App\Services\Crm\PlanScope;
use App\Services\Crm\PlanScopeResolver;
use App\Services\Crm\SalesPlanService;
use App\Services\SimpleXlsxExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Планы продаж: сколько отдел, менеджер и партнёр должны дать выручки за месяц,
 * и сколько дали на самом деле.
 *
 * Ввод и хранение — `SalesPlanService`; выполнение, прогноз и burndown —
 * `PlanProgressService` поверх `ShipmentAnalyticsService`. Второго движка расчёта
 * продаж не заводим: расхождение /crm/plans с /crm/analytics — баг по определению.
 */
class PlanController extends CrmController
{
    public function __construct(
        private readonly SalesPlanService $plans,
        private readonly PlanProgressService $progress,
        private readonly PlanScopeResolver $scopes,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', CrmSalesPlan::class);

        return Inertia::render('Crm/Pages/Plans/Index', $this->payload($request));
    }

    /**
     * Тот же payload в JSON: сетка перезагружается после сохранения без полного
     * визита, и этот же ответ переиспользует агентский API волны 5.
     */
    public function data(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CrmSalesPlan::class);

        return response()->json($this->payload($request));
    }

    public function store(StoreSalesPlansRequest $request): JsonResponse
    {
        Gate::authorize('create', CrmSalesPlan::class);

        $actor = $this->crmActor($request);
        $month = $this->plans->parseMonth($request->string('month')->value());

        /** @var list<array<string, mixed>> $rows */
        $rows = $request->input('rows', []);

        $result = $this->plans->bulkSet($rows, $actor, $month);

        return response()->json($result);
    }

    public function copyPrevious(Request $request): JsonResponse
    {
        Gate::authorize('create', CrmSalesPlan::class);

        $request->validate([
            'month' => ['nullable', 'string', 'max:10'],
            'overwrite' => ['nullable', 'boolean'],
        ], [
            'month.max' => 'Некорректный месяц.',
        ]);

        $month = $this->plans->parseMonth($request->string('month')->value());

        $result = $this->plans->copyFromPreviousMonth(
            $month,
            $this->crmActor($request),
            $request->boolean('overwrite'),
        );

        return response()->json($result);
    }

    /**
     * Сводка выполнения: план, факт, остаток, прогноз при текущем темпе.
     * GET /crm/plans/progress
     */
    public function progressData(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CrmSalesPlan::class);

        $actor = $this->crmActor($request);
        $month = $this->plans->parseMonth($request->string('month')->value());
        $scope = $this->resolveScope($request, $actor);

        return response()->json([
            'month' => $month->format('Y-m'),
            'monthLabel' => $this->plans->monthLabel($month),
            'scope' => $this->scopes->payload($scope),
            'summary' => $this->progress->progress($month, $scope),
            'distribution' => $this->progress->distribution($month, $scope),
            'clients' => $this->progress->clients($month, $scope, $actor),
            'scopeOptions' => $this->scopes->options($actor),
            'canSeeAll' => $this->seesManagerBreakdown($request),
        ]);
    }

    /**
     * Точки burndown по дням месяца. GET /crm/plans/burndown
     */
    public function burndown(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CrmSalesPlan::class);

        $month = $this->plans->parseMonth($request->string('month')->value());
        $scope = $this->resolveScope($request, $this->crmActor($request));

        return response()->json([
            'month' => $month->format('Y-m'),
            'plan' => $this->progress->planAmount($month, $scope),
            'points' => $this->progress->burndown($month, $scope),
        ]);
    }

    /**
     * Разрез по менеджерам. GET /crm/plans/by-manager
     *
     * Маршрут закрыт `crm-clients-all.view`: чужая цифра выручки не дело
     * соседнего менеджера.
     */
    public function byManager(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CrmSalesPlan::class);

        $month = $this->plans->parseMonth($request->string('month')->value());

        return response()->json([
            'month' => $month->format('Y-m'),
            'rows' => $this->progress->byManager($month, $this->crmActor($request)),
        ]);
    }

    /**
     * XLSX выполнения планов: сводка, менеджеры (если доступны), партнёры.
     * GET /crm/plans/export
     */
    public function export(Request $request, SimpleXlsxExporter $exporter): StreamedResponse
    {
        Gate::authorize('viewAny', CrmSalesPlan::class);

        $actor = $this->crmActor($request);
        $month = $this->plans->parseMonth($request->string('month')->value());
        $scope = $this->resolveScope($request, $actor);

        $summary = $this->progress->progress($month, $scope);
        $headers = ['Раздел', 'Объект', 'План, ₽', 'Факт, ₽', 'Выполнение, %', 'Отставание, ₽', 'Прогноз при текущем темпе, ₽'];

        $rows = [[
            'Сводка',
            $scope->label,
            $summary['plan'] ?? 0,
            $summary['fact'],
            $summary['percent'] ?? '',
            $summary['remaining'] ?? '',
            $summary['forecast'] ?? '',
        ]];

        foreach ($this->progress->byManager($month, $actor) as $row) {
            $rows[] = [
                'Менеджеры',
                $row['name'],
                $row['plan'] ?? 0,
                $row['fact'],
                $row['percent'] ?? '',
                $row['plan'] !== null ? round(max(0.0, $row['plan'] - $row['fact']), 2) : '',
                $row['forecast'] ?? '',
            ];
        }

        foreach ($this->progress->clients($month, $scope, $actor) as $row) {
            $rows[] = [
                'Партнёры',
                $row['name'],
                $row['plan'] ?? 0,
                $row['fact'],
                $row['percent'] ?? '',
                $row['lag'] ?? '',
                '',
            ];
        }

        return $exporter->stream(
            'crm-plan-progress-'.$month->format('Y-m'),
            $headers,
            $rows,
            'Выполнение планов',
        );
    }

    public function destroy(Request $request, int $plan): JsonResponse
    {
        // Резолвим через скоуп: чужой план — 404, а не 403. Иначе перебором id
        // можно было бы узнать, что план соседнего менеджера существует.
        $model = $this->plans->visibleTo($this->crmActor($request))->findOrFail($plan);

        Gate::authorize('delete', $model);

        $model->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Скоуп расчёта выполнения из запроса — правила доступа живут в резолвере.
     */
    private function resolveScope(Request $request, User $actor): PlanScope
    {
        return $this->scopes->resolve(
            $actor,
            $request->string('scope')->value() ?: null,
            (int) $request->input('scope_id', 0) ?: null,
        );
    }

    /**
     * Сетка планов на месяц: отдел, менеджеры, партнёры.
     *
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $actor = $this->crmActor($request);
        $seesAll = $this->seesManagerBreakdown($request);

        $month = $this->plans->parseMonth($request->string('month')->value());
        $previousMonth = $month->copy()->subMonthNoOverflow();

        $plans = $this->plans->indexedByTarget($actor, $month);
        $previous = $this->plans->indexedByTarget($actor, $previousMonth);

        $departmentKey = PlanTarget::DEPARTMENT->value;

        $filters = [
            'search' => $request->string('search')->value(),
            'manager_id' => $seesAll ? $request->input('manager_id') : null,
            'only_with_plan' => $request->boolean('only_with_plan'),
            'per_page' => (int) $request->input('per_page', 25),
        ];

        $managers = $this->plans->managerRows($actor, $month, $plans, $previous);

        return [
            'month' => $month->format('Y-m'),
            'monthLabel' => $this->plans->monthLabel($month),
            'previousMonth' => $previousMonth->format('Y-m'),
            'previousMonthLabel' => $this->plans->monthLabel($previousMonth),
            'department' => [
                'amount' => isset($plans[$departmentKey]) ? $plans[$departmentKey]->amountValue() : null,
                'previous_amount' => isset($previous[$departmentKey]) ? $previous[$departmentKey]->amountValue() : null,
                'comment' => $plans[$departmentKey]->comment ?? null,
                'can_edit' => $this->plans->canManage($actor, PlanTarget::DEPARTMENT, null),
            ],
            'managers' => $managers,
            'managersSum' => array_sum(array_map(
                fn (array $row): float => (float) ($row['amount'] ?? 0),
                $managers,
            )),
            'clients' => $this->plans->clientRows($actor, $month, $filters),
            'managerOptions' => $seesAll
                ? PersonalManager::query()->active()->select('id', 'name')->orderBy('name')->get()
                : [],
            'canSeeAll' => $seesAll,
            'canEdit' => $actor->can('crm-plans.edit'),
            'filters' => $filters,
        ];
    }
}
