<?php

namespace App\Services\Crm;

use App\Enums\Crm\TaskPriority;
use App\Enums\Crm\TaskStatus;
use App\Models\CrmComment;
use App\Models\CrmTask;
use App\Models\User;
use App\Notifications\Crm\TaskAssignedNotification;
use App\Support\Crm\CrmAttachments;
use App\Support\Crm\CrmEntityMap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Работа с задачами менеджеров: скоуп видимости, создание, правка, форма ответа.
 *
 * Единая точка на все пути: раздел «Задачи», врезка в карточке сущности и (позже)
 * ИИ-агент менеджера. Иначе назначение исполнителя, уведомление и проверка доступа
 * повторялись бы в каждом контроллере — и однажды разошлись бы.
 */
class CrmTaskService
{
    /**
     * Задачи, доступные актору.
     *
     * Рядовой менеджер видит те, в которых участвует; РОП — все задачи отдела.
     * Ровно та же граница, что в `CrmTaskPolicy::view()`: список и политика обязаны
     * говорить одно и то же, иначе задача из списка открывалась бы в 403.
     *
     * @return Builder<CrmTask>
     */
    public function visibleTo(User $actor): Builder
    {
        $query = CrmTask::query();

        if (! $actor->can('crm-department.view')) {
            $actorId = (int) $actor->getKey();

            $query->where(fn (Builder $inner) => $inner
                ->where('author_id', $actorId)
                ->orWhere('assignee_id', $actorId));
        }

        return $query;
    }

    /**
     * Партнёры актора, по которым не поставлено ни одной незакрытой задачи.
     *
     * Проактивность продаж измеряется не количеством закрытых задач, а тем, что
     * по каждому партнёру известен следующий шаг. Партнёр без активной задачи — это
     * партнёр, о котором вспомнят только когда он сам позвонит.
     *
     * @return Builder<User>
     */
    public function uncoveredClients(User $actor): Builder
    {
        return User::query()
            ->visibleInCrm($actor)
            ->whereDoesntHave('crmTasks', fn (Builder $tasks) => $tasks
                ->whereIn('status', TaskStatus::activeValues()));
    }

    /**
     * Подсчёт незакрытых задач партнёра — для колонки «Задачи» в списке.
     *
     * @return array<string, \Closure>
     */
    public function activeTasksCount(): array
    {
        return [
            'crmTasks as active_tasks_count' => fn (Builder $tasks) => $tasks
                ->whereIn('status', TaskStatus::activeValues()),
        ];
    }

    /**
     * Кому можно поручить задачу.
     *
     * Только сотрудники с доступом в CRM: задача, поставленная кладовщику или партнёру,
     * не появилась бы ни в одном интерфейсе и просто потерялась бы.
     *
     * @return list<array{id: int, name: string}>
     */
    public function assignableUsers(): array
    {
        // Кандидаты — только сотрудники (у кого есть роль): CRM-доступ бывает и через
        // роль, и через прямое право, поэтому окончательная проверка идёт в PHP.
        // Перебирать так всю таблицу users нельзя — партнёров там тысячи, сотрудников десятки.
        return User::query()
            ->select('id', 'name')
            ->whereHas('roles')
            ->with(['roles.permissions:id,name', 'permissions:id,name'])
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => $user->hasCrmAccess())
            ->map(fn (User $user): array => [
                'id' => (int) $user->getKey(),
                'name' => (string) $user->name,
            ])
            ->values()
            ->all();
    }

    public function canBeAssigned(int $userId): bool
    {
        $user = User::query()->find($userId);

        return $user !== null && $user->hasCrmAccess();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data, ?Model $related): CrmTask
    {
        $task = new CrmTask([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'assignee_id' => (int) ($data['assignee_id'] ?? $actor->getKey()),
            'status' => $data['status'] ?? TaskStatus::OPEN->value,
            'priority' => $data['priority'] ?? TaskPriority::NORMAL->value,
            'due_at' => $this->parseDue($data['due_at'] ?? null),
        ]);

        if ($related !== null) {
            $task->related()->associate($related);
        }

        $task->author_id = (int) $actor->getKey();
        // client_user_id и done_at проставляет сама модель — единая точка на все пути.
        $task->save();

        $this->notifyAssignee($task, $actor);

        return $task;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CrmTask $task, array $data, User $actor): CrmTask
    {
        $previousAssignee = (int) $task->assignee_id;

        foreach (['title', 'description', 'status', 'priority'] as $field) {
            if (array_key_exists($field, $data)) {
                $task->{$field} = $data[$field];
            }
        }

        if (array_key_exists('due_at', $data)) {
            $task->due_at = $this->parseDue($data['due_at']);
        }

        // Переназначение — отдельное право: исполнитель может закрыть задачу,
        // но не перевесить её на третьего.
        if (array_key_exists('assignee_id', $data) && $actor->can('reassign', $task)) {
            $task->assignee_id = (int) $data['assignee_id'];
        }

        $task->save();

        if ((int) $task->assignee_id !== $previousAssignee) {
            $this->notifyAssignee($task, $actor);
        }

        return $task;
    }

    /**
     * Закрытие задачи: отметка о выполнении, комментарий и следующий шаг.
     *
     * Всё тремя записями в одной транзакции: закрытие с потерянным комментарием
     * или с несозданной следующей задачей — это ровно тот разрыв, из-за которого
     * работа с партнёром и рассыпается на разовые дёрганья.
     *
     * Комментарий и follow-up необязательны: заставлять писать отчёт по каждой
     * мелочи — верный способ научить людей не закрывать задачи вовсе.
     *
     * @param  array<string, mixed>|null  $followUp
     * @return array{task: CrmTask, follow_up: CrmTask|null}
     */
    public function close(CrmTask $task, User $actor, ?string $comment = null, ?array $followUp = null): array
    {
        return DB::transaction(function () use ($task, $actor, $comment, $followUp): array {
            $task->status = TaskStatus::DONE;
            $task->save();

            $comment = $comment === null ? null : trim($comment);

            if ($comment !== null && $comment !== '') {
                $record = new CrmComment(['body' => $comment]);
                $record->commentable()->associate($task);
                $record->user_id = (int) $actor->getKey();
                $record->save();
            }

            $next = null;

            if ($followUp !== null && ($followUp['title'] ?? '') !== '') {
                $next = $this->create(
                    $actor,
                    [
                        'title' => $followUp['title'],
                        'description' => $followUp['description'] ?? null,
                        // По умолчанию следующий шаг остаётся за тем же человеком:
                        // чаще всего это продолжение своей же работы с партнёром.
                        'assignee_id' => $followUp['assignee_id'] ?? $task->assignee_id,
                        'priority' => $followUp['priority'] ?? $task->priority->value,
                        'due_at' => $followUp['due_at'] ?? null,
                    ],
                    // Привязка наследуется: следующий шаг по партнёру — это шаг
                    // по тому же партнёру, и заново искать его незачем.
                    $task->related,
                );

                $next->follow_up_of_id = (int) $task->getKey();
                $next->save();
            }

            return ['task' => $task, 'follow_up' => $next];
        });
    }

    /**
     * Уведомление исполнителю — за фича-флагом, как все письма проекта.
     *
     * Себе задачу ставят чаще, чем коллеге, и письмо о собственном поручении —
     * это спам, поэтому автору-исполнителю не пишем.
     */
    private function notifyAssignee(CrmTask $task, User $actor): void
    {
        if (! config('notifications.mail.features.crm_tasks')) {
            return;
        }

        if ((int) $task->assignee_id === (int) $actor->getKey()) {
            return;
        }

        $task->loadMissing('assignee');
        $task->assignee->notify(new TaskAssignedNotification($task));
    }

    /**
     * Дедлайн из формы: пустая строка — это «без срока», а не «сегодня».
     */
    private function parseDue(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value);
    }

    /**
     * Форма задачи для фронта — одна на список, врезку и ленту партнёра.
     *
     * @return array<string, mixed>
     */
    public function payload(CrmTask $task, User $viewer): array
    {
        $related = $task->related;

        return [
            'id' => (int) $task->getKey(),
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status->value,
            'status_label' => $task->status->label(),
            'status_color' => $task->status->color(),
            'priority' => $task->priority->value,
            'priority_label' => $task->priority->label(),
            'priority_color' => $task->priority->color(),
            // ISO — для формы редактирования, лейбл — для показа: собирать второе
            // из первого на фронте означало бы дублировать формат даты в JSX.
            'due_at' => $task->due_at?->format('Y-m-d\TH:i'),
            'due_at_label' => $task->due_at?->format('d.m.Y H:i'),
            'done_at_label' => $task->done_at?->format('d.m.Y H:i'),
            'is_overdue' => $task->isOverdue(),
            'created_at_label' => $task->created_at?->format('d.m.Y H:i'),
            'author' => [
                'id' => (int) $task->author_id,
                'name' => $task->author->name,
            ],
            'assignee' => [
                'id' => (int) $task->assignee_id,
                'name' => $task->assignee->name,
            ],
            'client_id' => $task->client_user_id === null ? null : (int) $task->client_user_id,
            'entity' => $related instanceof Model
                ? CrmEntityMap::describe($related, $viewer)
                : null,
            // В списках счётчик приходит из withCount(); на одиночных путях его нет —
            // там дешевле досчитать, чем показать 0 и потерять скрепку у задачи с файлами.
            'attachments_count' => (int) ($task->attachments_count
                ?? $task->media()->where('collection_name', CrmAttachments::COLLECTION)->count()),
            'can' => [
                'update' => $viewer->can('update', $task),
                'reassign' => $viewer->can('reassign', $task),
                'delete' => $viewer->can('delete', $task),
            ],
        ];
    }
}
