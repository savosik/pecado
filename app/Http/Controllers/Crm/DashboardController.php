<?php

namespace App\Http\Controllers\Crm;

use App\Models\CrmTask;
use App\Models\User;
use App\Services\Crm\CrmTaskService;
use App\Services\Crm\OpportunityService;
use App\Services\Crm\PlanScopeResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends CrmController
{
    public function index(
        Request $request,
        CrmTaskService $tasks,
        OpportunityService $opportunities,
        PlanScopeResolver $scopes,
    ): Response {
        $actor = $this->crmActor($request);
        $seesAll = $this->seesDepartment($request);

        // Тот же scope, что и в списке партнёров, — цифры не разъедутся с выдачей.
        $visibleClients = User::query()->visibleInCrm($actor)->count();

        return Inertia::render('Crm/Pages/Dashboard', [
            'tasks' => $actor->can('crm-tasks.view') ? $this->todayTasks($actor, $tasks) : null,
            'coverage' => $actor->can('crm-tasks.view') ? $this->coverage($actor, $tasks) : null,
            // Топ-5 возможностей за текущий месяц: пресет здесь не спрашивается,
            // на рабочем столе нужен ответ «что делать сейчас», а не выбор среза.
            'opportunities' => $actor->can('crm-opportunities.view')
                ? $opportunities->top(now(), $scopes->resolve($actor, null, null))
                : null,
            'stats' => [
                'visible_clients' => $visibleClients,
                'department_clients' => $seesAll
                    ? User::query()->clients()->whereNotNull('personal_manager_id')->count()
                    : null,
                'managers' => $seesAll
                    ? \App\Models\PersonalManager::query()->active()->count()
                    : null,
            ],
            'seesAll' => $seesAll,
            'managerProfileLinked' => $seesAll || $actor->managerProfile !== null,
        ]);
    }

    /**
     * Покрытие партнёров задачами: по кому не поставлено ни одного следующего шага.
     *
     * Показываем не только цифру, но и первых из списка с кнопкой «поставить задачу»:
     * отчёт, из которого нельзя ничего сделать, читают один раз.
     *
     * @return array<string, mixed>
     */
    private function coverage(User $actor, CrmTaskService $tasks): array
    {
        $uncovered = $tasks->uncoveredClients($actor);

        $total = User::query()->visibleInCrm($actor)->count();
        $uncoveredCount = (clone $uncovered)->count();

        return [
            'clients_total' => $total,
            'uncovered_count' => $uncoveredCount,
            'covered_percent' => $total === 0 ? null : (int) round(($total - $uncoveredCount) / $total * 100),
            'examples' => $uncovered
                ->select('id', 'name', 'erp_name')
                ->orderBy('name')
                ->take(5)
                ->get()
                ->map(fn (User $client): array => [
                    'id' => (int) $client->getKey(),
                    'name' => (string) $client->display_name,
                ])
                ->all(),
        ];
    }

    /**
     * Виджет «Мои задачи на сегодня»: просроченное и то, что горит сегодня.
     *
     * Просроченное показываем вместе с сегодняшним, а не отдельным списком:
     * менеджеру важно «что делать сейчас», а не «в какой день это протухло».
     *
     * @return array<string, mixed>
     */
    private function todayTasks(User $actor, CrmTaskService $tasks): array
    {
        $actorId = (int) $actor->getKey();

        $items = $tasks->visibleTo($actor)
            ->assignedTo($actorId)
            ->where(fn ($query) => $query->overdue()->orWhere(fn ($today) => $today->dueToday()))
            ->with(['author:id,name', 'assignee:id,name', 'related'])
            ->orderBy('due_at')
            ->take(8)
            ->get()
            ->map(fn (CrmTask $task) => $tasks->payload($task, $actor))
            ->all();

        return [
            'items' => $items,
            'overdue_count' => $tasks->visibleTo($actor)->assignedTo($actorId)->overdue()->count(),
            'today_count' => $tasks->visibleTo($actor)->assignedTo($actorId)->dueToday()->count(),
        ];
    }
}
