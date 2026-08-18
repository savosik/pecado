<?php

namespace App\Services\Crm;

use App\Enums\Crm\TaskStatus;
use App\Models\CrmTask;
use App\Models\CrmTaskReminderLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;

/**
 * Единый контур напоминаний по задачам — общий для тостов, писем и push.
 *
 * Правило одно: повод (задача × получатель × kind) отправляется в канал один
 * раз, отметка — строка лога с уникальным ключом. Перенос срока стирает
 * отметки `due`/`overdue`, и к новому сроку напоминание срабатывает снова.
 */
class TaskReminderService
{
    public function __construct(private readonly CrmTaskService $tasks) {}

    /**
     * Свежие поводы для тостов актора. Отметка пишется сразу — вторая вкладка
     * тот же повод уже не получит: дедупликацию делает сервер, не localStorage.
     *
     * @return list<array<string, mixed>>
     */
    public function claimToastReminders(User $actor): array
    {
        $reminders = [];

        foreach ($this->pendingFor($actor) as [$task, $kind]) {
            if (! $this->claim($task, $actor, $kind, CrmTaskReminderLog::CHANNEL_TOAST)) {
                continue;
            }

            $reminders[] = [
                'kind' => $kind,
                'kind_label' => $this->kindLabel($kind),
                'sticky' => in_array($kind, [CrmTaskReminderLog::KIND_DUE, CrmTaskReminderLog::KIND_OVERDUE], true),
                'task' => $this->tasks->payload($task, $actor),
            ];
        }

        return $reminders;
    }

    /**
     * Поводы, ещё не отмеченные ни в одном представлении для актора.
     *
     * @return list<array{0: CrmTask, 1: string}>
     */
    private function pendingFor(User $actor): array
    {
        $actorId = (int) $actor->getKey();
        $pairs = [];

        $base = fn (): Builder => CrmTask::query()
            ->whereIn('status', TaskStatus::activeValues())
            ->where(fn (Builder $inner) => $inner
                ->where('assignee_id', $actorId)
                ->orWhereHas('coAssignees', fn (Builder $users) => $users->whereKey($actorId)))
            ->with(['author:id,name', 'assignee:id,name', 'coAssignees:id,name', 'watchers:id,name', 'tags', 'related']);

        // Срок наступил: due_at уже позади, но не глубже суток — древнюю просрочку
        // при первом входе после отпуска не вываливаем стеной тостов.
        $due = $base()
            ->whereBetween('due_at', [now()->subDay(), now()])
            ->take(10)
            ->get();

        foreach ($due as $task) {
            $pairs[] = [$task, CrmTaskReminderLog::KIND_DUE];
        }

        // Просрочена: сутки после срока — второй и последний сигнал.
        $overdue = $base()
            ->whereBetween('due_at', [now()->subDays(2), now()->subDay()])
            ->take(10)
            ->get();

        foreach ($overdue as $task) {
            $pairs[] = [$task, CrmTaskReminderLog::KIND_OVERDUE];
        }

        // Назначена: свежая задача от другого автора.
        $assigned = $base()
            ->where('created_at', '>=', now()->subDay())
            ->whereNot('author_id', $actorId)
            ->take(10)
            ->get();

        foreach ($assigned as $task) {
            $pairs[] = [$task, CrmTaskReminderLog::KIND_ASSIGNED];
        }

        return $pairs;
    }

    /**
     * Поводы для push-канала: те же, что у тостов, плюс заголовок уведомления.
     *
     * @return list<array{0: CrmTask, 1: string, 2: string}>
     */
    public function pendingPushFor(User $actor): array
    {
        return array_map(
            fn (array $pair): array => [$pair[0], $pair[1], $this->kindLabel($pair[1])],
            $this->pendingFor($actor),
        );
    }

    /**
     * Отметить повод отправленным. false — уже отправляли (или гонка вкладок).
     */
    public function claim(CrmTask $task, User $recipient, string $kind, string $channel): bool
    {
        try {
            CrmTaskReminderLog::query()->create([
                'task_id' => (int) $task->getKey(),
                'user_id' => (int) $recipient->getKey(),
                'kind' => $kind,
                'channel' => $channel,
                'sent_at' => now(),
            ]);

            return true;
        } catch (QueryException $exception) {
            if ($this->isDuplicate($exception)) {
                return false;
            }

            throw $exception;
        }
    }

    /**
     * Перенос срока: поводы о сроке начинаются заново, во всех каналах.
     */
    public function resetDueReminders(CrmTask $task): void
    {
        CrmTaskReminderLog::query()
            ->where('task_id', (int) $task->getKey())
            ->whereIn('kind', [
                CrmTaskReminderLog::KIND_DUE,
                CrmTaskReminderLog::KIND_DUE_SOON,
                CrmTaskReminderLog::KIND_OVERDUE,
            ])
            ->delete();
    }

    private function kindLabel(string $kind): string
    {
        return match ($kind) {
            CrmTaskReminderLog::KIND_ASSIGNED => 'Вам назначена задача',
            CrmTaskReminderLog::KIND_DUE => 'Наступил срок задачи',
            CrmTaskReminderLog::KIND_DUE_SOON => 'Срок задачи завтра',
            CrmTaskReminderLog::KIND_OVERDUE => 'Задача просрочена',
            default => 'Задача',
        };
    }

    /**
     * Именно дубль по уникальному ключу лога, а не любое нарушение целостности.
     */
    private function isDuplicate(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'crm_task_reminder_once')
            || (str_contains($message, 'UNIQUE constraint failed')
                && str_contains($message, 'crm_task_reminder_logs'));
    }
}
