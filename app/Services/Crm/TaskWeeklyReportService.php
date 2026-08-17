<?php

namespace App\Services\Crm;

use App\Enums\Crm\TaskOutcome;
use App\Enums\Crm\TaskStatus;
use App\Models\CrmComment;
use App\Models\CrmTask;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Недельная статистика задач для пятничного отчёта (task-10).
 *
 * Все цифры считаются из фактов модели (done_at, outcome, due_at, комментарии
 * переносов) — никаких накопительных таблиц: отчёт можно пересобрать за любую
 * прошлую неделю, и он не разъедется с данными.
 */
class TaskWeeklyReportService
{
    /**
     * Менеджеры, которым есть о чём писать: было движение за неделю
     * или есть незакрытые хвосты.
     *
     * @return list<int>
     */
    public function activeManagerIds(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = [];

        CrmTask::query()
            ->where(fn (Builder $query) => $query
                ->whereBetween('done_at', [$from, $to])
                ->orWhere(fn (Builder $inner) => $inner
                    ->whereIn('status', TaskStatus::activeValues())
                    ->whereNotNull('due_at')
                    ->where('due_at', '<', $to->addDays(7))))
            ->with('coAssignees:id')
            ->get(['id', 'assignee_id'])
            ->each(function (CrmTask $task) use (&$ids) {
                $ids[] = (int) $task->assignee_id;

                foreach ($task->coAssignees as $user) {
                    $ids[] = (int) $user->getKey();
                }
            });

        return array_values(array_unique($ids));
    }

    /**
     * Личная сводка менеджера за неделю.
     *
     * @return array<string, mixed>
     */
    public function managerStats(User $manager, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $managerId = (int) $manager->getKey();

        $participant = fn (): Builder => CrmTask::query()
            ->where(fn (Builder $inner) => $inner
                ->where('assignee_id', $managerId)
                ->orWhereHas('coAssignees', fn (Builder $users) => $users->whereKey($managerId)));

        $closed = $participant()
            ->where('status', TaskStatus::DONE->value)
            ->whereBetween('done_at', [$from, $to])
            ->get();

        $problem = $closed->filter(fn (CrmTask $task): bool => $task->outcome === TaskOutcome::PROBLEM);
        $withDue = $closed->filter(fn (CrmTask $task): bool => $task->due_at !== null);
        $onTime = $withDue->filter(fn (CrmTask $task): bool => $task->done_at !== null && $task->done_at->lte($task->due_at));

        // Переносы недели — по системным комментариям: postponed_count
        // накопительный и о конкретной неделе ничего не знает.
        $postpones = CrmComment::query()
            ->where('commentable_type', CrmTask::class)
            ->whereIn('commentable_id', $participant()->select('id'))
            ->where('user_id', $managerId)
            ->where('body', 'like', 'Перенесена с «%')
            ->whereBetween('created_at', [$from, $to])
            ->count();

        $overdueNow = $participant()->overdue()
            ->orderBy('due_at')
            ->get(['id', 'title', 'due_at']);

        $nextWeek = $participant()
            ->whereIn('status', TaskStatus::activeValues())
            ->whereBetween('due_at', [$to, $to->addDays(7)])
            ->get(['id', 'estimate_minutes']);

        return [
            'closed_total' => $closed->count(),
            'closed_success' => $closed->count() - $problem->count(),
            'closed_problem' => $problem->count(),
            'problem_titles' => $problem->take(10)->pluck('title')->all(),
            'with_due' => $withDue->count(),
            'on_time' => $onTime->count(),
            'postpones' => $postpones,
            'overdue_now' => $overdueNow->count(),
            'overdue_titles' => $overdueNow->take(5)
                ->map(fn (CrmTask $task): string => sprintf(
                    '%s (просрочена на %d дн.)',
                    $task->title,
                    (int) $task->due_at->diffInDays(now()),
                ))
                ->all(),
            'next_week_count' => $nextWeek->count(),
            'next_week_minutes' => (int) $nextWeek->sum('estimate_minutes'),
        ];
    }

    /**
     * Есть ли в личной сводке хоть что-то, ради чего стоит слать письмо.
     *
     * @param  array<string, mixed>  $stats
     */
    public function isWorthSending(array $stats): bool
    {
        return $stats['closed_total'] > 0
            || $stats['overdue_now'] > 0
            || $stats['next_week_count'] > 0
            || $stats['postpones'] > 0;
    }
}
