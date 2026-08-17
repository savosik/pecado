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
     * @return array<string, int>
     */
    private function shortagesCounter(\App\Models\User $user): array
    {
        if (! config('substitutions.enabled') || ! $user->can('crm-shortages.view')) {
            return [];
        }

        $query = \App\Models\SubstitutionOffer::query()
            ->where('status', \App\Enums\Substitution\OfferStatus::PENDING);

        // Менеджер видит счётчик по своим подборкам, отдел — по всем.
        if (! $user->can('crm-department.view')) {
            $managerId = $user->managerProfile?->id;

            $query->where(function ($q) use ($user, $managerId) {
                $q->where('manager_user_id', $user->id);

                if ($managerId !== null) {
                    $q->orWhereHas('user', fn ($u) => $u->where('personal_manager_id', $managerId));
                }
            });
        }

        return ['shortages' => $query->count()];
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

        $count = $tasks->visibleTo($user)->assignedTo($actorId)->overdue()->count()
            + $tasks->visibleTo($user)->assignedTo($actorId)->dueToday()->count();

        return ['tasks' => $count];
    }
}
