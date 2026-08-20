<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\SharesPanelAuth;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleCrmInertiaRequests extends Middleware
{
    use SharesPanelAuth;

    protected $rootView = 'crm';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => $this->panelAuthProps($request->user()),
            'flash' => $this->panelFlashProps($request),
            'crmCounters' => fn () => $this->counters($request),
        ];
    }

    /**
     * Числовые бейджи пунктов меню CRM.
     *
     * Лениво (fn) — считается только когда страница реально рендерится;
     * ключ = значение `counter` пункта в menuConfig.ts.
     *
     * @return array<string, int>
     */
    private function counters(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [];
        }

        return [
            ...$this->shortagesCounter($user),
            ...$this->tasksCounter($user),
        ];
    }

    /**
     * Неразмеченные отмены в журнале недоборов — то, из-за чего стоит зайти
     * в раздел: строка отменилась, а причину («склад» или «клиент») ещё
     * никто не проставил.
     *
     * @return array<string, int>
     */
    private function shortagesCounter(\App\Models\User $user): array
    {
        if (! $user->can('crm-shortages.view')) {
            return [];
        }

        return ['shortages' => app(\App\Services\Shortage\ShortageLogQuery::class)->unmarkedCount($user)];
    }

    /**
     * Задачи актора «просрочено + сегодня»: то, из-за чего стоит зайти в раздел.
     *
     * Два count-запроса по индексу (assignee_id, status, due_at) — дёшево на
     * каждый Inertia-рендер; polling-обновление между переходами делает task-08.
     *
     * @return array<string, int>
     */
    private function tasksCounter(\App\Models\User $user): array
    {
        if (! $user->can('crm-tasks.view')) {
            return [];
        }

        $tasks = app(\App\Services\Crm\CrmTaskService::class);
        $actorId = (int) $user->getKey();

        // Одно условие «due_at до конца дня»: сумма скоупов overdue+dueToday
        // посчитала бы просроченную сегодня задачу дважды.
        $count = $tasks->visibleTo($user)
            ->assignedTo($actorId)
            ->whereIn('status', \App\Enums\Crm\TaskStatus::activeValues())
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now()->endOfDay())
            ->count();

        return ['tasks' => $count];
    }
}
