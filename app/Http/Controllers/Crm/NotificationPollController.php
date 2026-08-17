<?php

namespace App\Http\Controllers\Crm;

use App\Models\CrmTask;
use App\Services\Crm\CrmTaskService;
use App\Services\Crm\TaskReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Один polling-запрос на всё «живое» по задачам: счётчик меню + тосты.
 *
 * Вебсокетов в проекте нет намеренно — опрос раз в ~минуту по образцу
 * полоски присутствия. Запрос обязан оставаться дешёвым: индексные count-ы
 * и три коротких выборки в лимитах.
 */
class NotificationPollController extends CrmController
{
    public function __construct(
        private readonly CrmTaskService $tasks,
        private readonly TaskReminderService $reminders,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);
        Gate::authorize('viewAny', CrmTask::class);

        $actorId = (int) $actor->getKey();

        // Просрочено + сегодня одним условием: due_at до конца дня. Сумма двух
        // скоупов посчитала бы просроченную сегодня задачу дважды.
        $counter = $this->tasks->visibleTo($actor)
            ->assignedTo($actorId)
            ->whereIn('status', \App\Enums\Crm\TaskStatus::activeValues())
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now()->endOfDay())
            ->count();

        return response()->json([
            'counters' => ['tasks' => $counter],
            // Отметка «показано» пишется на сервере в момент выдачи: вторая
            // вкладка тот же повод уже не получит.
            'reminders' => $this->reminders->claimToastReminders($actor),
        ]);
    }
}
