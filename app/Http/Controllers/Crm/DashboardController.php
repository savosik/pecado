<?php

namespace App\Http\Controllers\Crm;

use App\Models\CrmTask;
use App\Models\User;
use App\Services\Crm\CrmTaskService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends CrmController
{
    public function index(Request $request, CrmTaskService $tasks): Response
    {
        $actor = $this->crmActor($request);
        $seesAll = $this->seesAllClients($request);

        // Тот же scope, что и в списке клиентов, — цифры не разъедутся с выдачей.
        $visibleClients = User::query()->visibleInCrm($actor)->count();

        return Inertia::render('Crm/Pages/Dashboard', [
            'tasks' => $actor->can('crm-tasks.view') ? $this->todayTasks($actor, $tasks) : null,
            'stats' => [
                'visible_clients' => $visibleClients,
                'department_clients' => $seesAll
                    ? User::query()->whereNotNull('personal_manager_id')->count()
                    : null,
                'managers' => $seesAll
                    ? \App\Models\PersonalManager::query()->count()
                    : null,
            ],
            'seesAll' => $seesAll,
            'managerProfileLinked' => $seesAll || $actor->managerProfile !== null,
        ]);
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
