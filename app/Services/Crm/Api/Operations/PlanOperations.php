<?php

namespace App\Services\Crm\Api\Operations;

use App\Models\CrmSalesPlan;
use App\Models\User;
use App\Services\Crm\Api\OperationInput;
use App\Services\Crm\PlanProgressService;
use App\Services\Crm\PlanScopeResolver;
use App\Services\Crm\SalesPlanService;
use Illuminate\Support\Facades\Gate;

/**
 * Планы продаж и их выполнение.
 *
 * Скоуп резолвится тем же `PlanScopeResolver`, что и в вебе. Это не удобство,
 * а граница доступа: менеджеру доступен только собственный скоуп, и второй её
 * реализацией однажды оказалось бы, что API показывает выручку соседа, а экран
 * нет — или наоборот.
 */
class PlanOperations
{
    public function __construct(
        private readonly SalesPlanService $plans,
        private readonly PlanProgressService $progress,
        private readonly PlanScopeResolver $scopes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function list(User $actor, OperationInput $input): array
    {
        Gate::forUser($actor)->authorize('viewAny', CrmSalesPlan::class);

        $month = $input->month();
        $indexed = $this->plans->indexedByTarget($actor, $month);

        $rows = [];

        foreach ($indexed as $key => $plan) {
            $rows[] = [
                'target' => $key,
                'target_type' => $plan->target_type->value,
                // У плана отдела здесь 0, а не NULL: MySQL не считает NULL-ы
                // равными, и уникальный индекс на цель периода не работал бы.
                'target_id' => (int) $plan->target_id,
                'amount' => (float) $plan->amount,
                'comment' => $plan->comment,
            ];
        }

        return [
            'month' => $month->format('Y-m'),
            'month_label' => $this->plans->monthLabel($month),
            'data' => $rows,
        ];
    }

    /**
     * Массовая постановка планов: строки «цель → сумма».
     *
     * Отдельного PATCH на один план нет и здесь — сетку правят строками, и
     * ставить план по одному означало бы N вызовов там, где нужен один.
     *
     * @return array<string, mixed>
     */
    public function set(User $actor, OperationInput $input): array
    {
        Gate::forUser($actor)->authorize('create', CrmSalesPlan::class);

        $month = $input->month();

        /** @var list<array<string, mixed>> $rows */
        $rows = array_values($input->array('rows'));

        $result = $this->plans->bulkSet($rows, $actor, $month);

        return $result + ['month' => $month->format('Y-m')];
    }

    /**
     * @return array<string, mixed>
     */
    public function progress(User $actor, OperationInput $input): array
    {
        Gate::forUser($actor)->authorize('viewAny', CrmSalesPlan::class);

        $month = $input->month();
        $scope = $this->scope($actor, $input);

        return [
            'month' => $month->format('Y-m'),
            'month_label' => $this->plans->monthLabel($month),
            'scope' => $this->scopes->payload($scope),
            'summary' => $this->progress->progress($month, $scope),
            'clients' => $this->progress->clients($month, $scope, $actor, min(200, max(1, (int) ($input->int('limit') ?? 50)))),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function burndown(User $actor, OperationInput $input): array
    {
        Gate::forUser($actor)->authorize('viewAny', CrmSalesPlan::class);

        $month = $input->month();

        return [
            'month' => $month->format('Y-m'),
            'data' => $this->progress->burndown($month, $this->scope($actor, $input)),
        ];
    }

    /**
     * Разрез по менеджерам. Право `crm-clients-all.view` — не косметика:
     * менеджер не должен видеть выручку соседа, даже зная адрес операции.
     *
     * @return array<string, mixed>
     */
    public function byManager(User $actor, OperationInput $input): array
    {
        Gate::forUser($actor)->authorize('viewAny', CrmSalesPlan::class);

        $month = $input->month();

        return [
            'month' => $month->format('Y-m'),
            'data' => $this->progress->byManager($month, $actor),
        ];
    }

    private function scope(User $actor, OperationInput $input): \App\Services\Crm\PlanScope
    {
        return $this->scopes->resolve(
            $actor,
            $input->string('scope'),
            $input->int('scope_id'),
        );
    }
}
