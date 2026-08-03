<?php

namespace App\Services\Crm;

use App\Enums\Crm\TaskPriority;
use App\Enums\Crm\TaskStatus;
use App\Models\CrmTask;
use App\Models\User;
use App\Notifications\Crm\TaskAssignedNotification;
use App\Support\Crm\CrmAttachments;
use App\Support\Crm\CrmEntityMap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

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

        if (! $actor->can('crm-clients-all.view')) {
            $actorId = (int) $actor->getKey();

            $query->where(fn (Builder $inner) => $inner
                ->where('author_id', $actorId)
                ->orWhere('assignee_id', $actorId));
        }

        return $query;
    }

    /**
     * Кому можно поручить задачу.
     *
     * Только сотрудники с доступом в CRM: задача, поставленная кладовщику или клиенту,
     * не появилась бы ни в одном интерфейсе и просто потерялась бы.
     *
     * @return list<array{id: int, name: string}>
     */
    public function assignableUsers(): array
    {
        // Кандидаты — только сотрудники (у кого есть роль): CRM-доступ бывает и через
        // роль, и через прямое право, поэтому окончательная проверка идёт в PHP.
        // Перебирать так всю таблицу users нельзя — клиентов там тысячи, сотрудников десятки.
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
     * Форма задачи для фронта — одна на список, врезку и ленту клиента.
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
