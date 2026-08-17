<?php

namespace App\Services\Crm\Api\Operations;

use App\Enums\Crm\TaskStatus;
use App\Models\CrmTask;
use App\Models\User;
use App\Services\Crm\Api\OperationInput;
use App\Services\Crm\CrmEntityResolver;
use App\Services\Crm\CrmTaskService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Задачи: список, карточка, постановка, правка и закрытие.
 *
 * Вся работа — через `CrmTaskService`: уведомление исполнителя, отметка
 * о выполнении и следующий шаг в одной транзакции живут там, и задача,
 * поставленная агентом, обязана проходить тот же путь, что и поставленная
 * руками. Иначе «поставил агент» означало бы «исполнитель не узнал».
 */
class TaskOperations
{
    public function __construct(
        private readonly CrmTaskService $tasks,
        private readonly CrmEntityResolver $resolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function list(User $actor, OperationInput $input): array
    {
        Gate::forUser($actor)->authorize('viewAny', CrmTask::class);

        $query = $this->tasks->visibleTo($actor)
            ->with(['author:id,name', 'assignee:id,name', 'coAssignees:id,name', 'watchers:id,name', 'related']);

        if ($input->has('status')) {
            $query->where('status', $input->string('status'));
        }

        if ($input->has('assignee_id')) {
            $query->where('assignee_id', $input->int('assignee_id'));
        }

        if ($input->has('client_id')) {
            $query->where('client_user_id', $input->int('client_id'));
        }

        if ($input->bool('overdue')) {
            $query->whereNotNull('due_at')
                ->where('due_at', '<', now())
                ->whereNot('status', TaskStatus::DONE->value);
        }

        $page = $query
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->orderByDesc('id')
            ->paginate(min(100, max(1, (int) ($input->int('per_page') ?? 30))));

        return [
            'data' => $page->getCollection()
                ->map(fn (CrmTask $task) => $this->tasks->payload($task, $actor))
                ->all(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $actor, OperationInput $input): array
    {
        $task = $this->task($actor, $input);

        return $this->tasks->payload($task, $actor);
    }

    /**
     * @return array<string, mixed>
     */
    public function create(User $actor, OperationInput $input): array
    {
        Gate::forUser($actor)->authorize('create', CrmTask::class);

        // Привязку резолвим до создания: иначе задача стала бы способом узнать
        // о существовании чужого партнёра и его документов.
        $related = $input->has('entity_type')
            ? $this->resolver->resolveForActor(
                $actor,
                (string) $input->string('entity_type'),
                (int) $input->int('entity_id'),
            )
            : null;

        $data = $input->only(['title', 'description', 'assignee_id', 'co_assignee_ids', 'status', 'priority', 'due_at', 'estimate_minutes']);

        // Исполнитель по умолчанию — сам актор: агент ставит задачу своему
        // менеджеру, и требовать явный assignee_id в каждом вызове незачем.
        if (! isset($data['assignee_id'])) {
            $data['assignee_id'] = $actor->getKey();
        }

        if (! $this->tasks->canBeAssigned((int) $data['assignee_id'])) {
            throw new \InvalidArgumentException('Исполнителем можно назначить только сотрудника с доступом в CRM.');
        }

        $task = $this->tasks->create($actor, $data, $related);
        $task->load(['author:id,name', 'assignee:id,name', 'coAssignees:id,name', 'watchers:id,name', 'related']);

        return $this->tasks->payload($task, $actor);
    }

    /**
     * @return array<string, mixed>
     */
    public function update(User $actor, OperationInput $input): array
    {
        $task = $this->task($actor, $input);
        Gate::forUser($actor)->authorize('update', $task);

        $task = $this->tasks->update(
            $task,
            $input->only(['title', 'description', 'status', 'priority', 'due_at', 'assignee_id', 'co_assignee_ids', 'estimate_minutes']),
            $actor,
        );
        $task->load(['author:id,name', 'assignee:id,name', 'coAssignees:id,name', 'watchers:id,name', 'related']);

        return $this->tasks->payload($task, $actor);
    }

    /**
     * @return array<string, mixed>
     */
    public function close(User $actor, OperationInput $input): array
    {
        $task = $this->task($actor, $input);
        Gate::forUser($actor)->authorize('update', $task);

        $followUp = $input->has('follow_up') ? $input->array('follow_up') : null;

        $outcome = $input->has('outcome')
            ? \App\Enums\Crm\TaskOutcome::from((string) $input->string('outcome'))
            : null;

        if ($outcome === \App\Enums\Crm\TaskOutcome::PROBLEM && trim((string) $input->string('comment')) === '') {
            throw new \InvalidArgumentException('При закрытии с проблемой опишите в comment, что пошло не так.');
        }

        $result = $this->tasks->close($task, $actor, $input->string('comment'), $followUp, $outcome);

        return [
            'task' => $this->tasks->payload($result['task'], $actor),
            'follow_up' => $result['follow_up'] === null
                ? null
                : $this->tasks->payload($result['follow_up'], $actor),
        ];
    }

    /**
     * Перенести срок задачи: due_at сдвигается, задача остаётся открытой.
     *
     * @return array<string, mixed>
     */
    public function postpone(User $actor, OperationInput $input): array
    {
        $task = $this->task($actor, $input);
        Gate::forUser($actor)->authorize('update', $task);

        $task = $this->tasks->postpone(
            $task,
            $actor,
            \Illuminate\Support\Carbon::parse((string) $input->string('due_at')),
            $input->string('reason'),
        );
        $task->load(['author:id,name', 'assignee:id,name', 'coAssignees:id,name', 'watchers:id,name', 'related']);

        return $this->tasks->payload($task, $actor);
    }

    /**
     * Добавить пункт чек-листа.
     *
     * @return array<string, mixed>
     */
    public function checklistAdd(User $actor, OperationInput $input): array
    {
        $task = $this->task($actor, $input);
        Gate::forUser($actor)->authorize('update', $task);

        $task->checklistItems()->create([
            'title' => trim((string) $input->string('title')),
            'position' => (int) ($task->checklistItems()->max('position') ?? 0) + 1,
        ]);

        $task->load('checklistItems.doneBy:id,name');

        return $this->tasks->checklistPayload($task);
    }

    /**
     * Отметить или снять пункт чек-листа.
     *
     * @return array<string, mixed>
     */
    public function checklistToggle(User $actor, OperationInput $input): array
    {
        $task = $this->task($actor, $input);
        Gate::forUser($actor)->authorize('update', $task);

        /** @var \App\Models\CrmTaskChecklistItem $item */
        $item = $task->checklistItems()->findOrFail((int) $input->int('item'));
        $item->markDone((bool) $input->bool('is_done'), (int) $actor->getKey());
        $item->save();

        $task->load('checklistItems.doneBy:id,name');

        return $this->tasks->checklistPayload($task);
    }

    /**
     * Задача из скоупа актора. Чужая — 404, как и партнёр вне скоупа.
     */
    private function task(User $actor, OperationInput $input): CrmTask
    {
        /** @var Builder<CrmTask> $query */
        $query = $this->tasks->visibleTo($actor);

        return $query->with(['author:id,name', 'assignee:id,name', 'coAssignees:id,name', 'watchers:id,name', 'related'])
            ->findOrFail((int) $input->int('task'));
    }
}
