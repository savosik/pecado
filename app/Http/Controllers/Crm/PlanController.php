<?php

namespace App\Http\Controllers\Crm;

use App\Enums\Crm\PlanTarget;
use App\Http\Requests\Crm\StoreSalesPlansRequest;
use App\Models\CrmSalesPlan;
use App\Models\PersonalManager;
use App\Services\Crm\SalesPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Планы продаж: сколько отдел, менеджер и клиент должны дать выручки за месяц.
 *
 * Здесь только ввод и хранение. Выполнение, прогноз и burndown считает `crm-06`
 * поверх `ShipmentAnalyticsService` — второго движка расчёта продаж не заводим.
 */
class PlanController extends CrmController
{
    public function __construct(private readonly SalesPlanService $plans) {}

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
     * Сетка планов на месяц: отдел, менеджеры, клиенты.
     *
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $actor = $this->crmActor($request);
        $seesAll = $this->seesAllClients($request);

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
                ? PersonalManager::query()->select('id', 'name')->orderBy('name')->get()
                : [],
            'canSeeAll' => $seesAll,
            'canEdit' => $actor->can('crm-plans.edit'),
            'filters' => $filters,
        ];
    }
}
