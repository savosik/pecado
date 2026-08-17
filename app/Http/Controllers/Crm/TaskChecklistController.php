<?php

namespace App\Http\Controllers\Crm;

use App\Models\CrmTask;
use App\Models\CrmTaskChecklistItem;
use App\Services\Crm\CrmTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Чек-лист задачи: пункты добавляют и отмечают участники задачи.
 *
 * Доступ везде — через политику самой задачи: у пункта нет собственной модели
 * доступа, он часть задачи.
 */
class TaskChecklistController extends CrmController
{
    public function __construct(private readonly CrmTaskService $tasks) {}

    public function store(Request $request, CrmTask $task): JsonResponse
    {
        $actor = $this->crmActor($request);
        Gate::authorize('update', $task);

        $validated = $request->validate([
            'title' => ['required', 'string', 'min:1', 'max:500'],
        ], [
            'title.required' => 'Введите текст пункта.',
            'title.max' => 'Пункт не может быть длиннее 500 символов.',
        ]);

        $item = new CrmTaskChecklistItem([
            'title' => trim($validated['title']),
            // В конец списка: перестановка — отдельным запросом.
            'position' => (int) ($task->checklistItems()->max('position') ?? 0) + 1,
        ]);
        $item->task()->associate($task);
        $item->save();

        return response()->json($this->checklistPayload($task), 201);
    }

    public function update(Request $request, CrmTask $task, CrmTaskChecklistItem $item): JsonResponse
    {
        $actor = $this->crmActor($request);
        Gate::authorize('update', $task);
        $this->assertBelongs($task, $item);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'min:1', 'max:500'],
            'is_done' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);

        if (array_key_exists('title', $validated)) {
            $item->title = trim($validated['title']);
        }

        if (array_key_exists('position', $validated)) {
            $item->position = (int) $validated['position'];
        }

        if (array_key_exists('is_done', $validated)) {
            $item->markDone((bool) $validated['is_done'], (int) $actor->getKey());
        }

        $item->save();

        return response()->json($this->checklistPayload($task));
    }

    public function destroy(Request $request, CrmTask $task, CrmTaskChecklistItem $item): JsonResponse
    {
        $this->crmActor($request);
        Gate::authorize('update', $task);
        $this->assertBelongs($task, $item);

        $item->delete();

        return response()->json($this->checklistPayload($task));
    }

    /**
     * Пункт из URL обязан принадлежать задаче из URL: иначе, зная ID чужого
     * пункта, его можно было бы править через собственную задачу.
     */
    private function assertBelongs(CrmTask $task, CrmTaskChecklistItem $item): void
    {
        abort_unless((int) $item->task_id === (int) $task->getKey(), 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function checklistPayload(CrmTask $task): array
    {
        $task->load('checklistItems.doneBy:id,name');

        return $this->tasks->checklistPayload($task);
    }
}
