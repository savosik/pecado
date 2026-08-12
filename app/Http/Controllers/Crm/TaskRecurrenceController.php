<?php

namespace App\Http\Controllers\Crm;

use App\Models\CrmTaskRecurrence;
use App\Services\Crm\CrmTaskRecurrenceService;
use App\Support\Crm\CrmEntityMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Правила автоповторяемых задач (crm-29).
 *
 * Отдельный ресурс, а не режим задачи: правило живёт своей жизнью — его ставят
 * на паузу, правят и отменяют, — и эта жизнь не должна волочить за собой
 * историю уже выполненных задач.
 *
 * Отдаётся JSON: управление цепочками встраивается врезкой в раздел задач,
 * а не занимает отдельный экран.
 */
class TaskRecurrenceController extends CrmController
{
    public function __construct(
        private readonly CrmTaskRecurrenceService $recurrences,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);

        $rules = $this->recurrences->visibleTo($actor)
            ->with(['assignee:id,name', 'author:id,name', 'client:id,name,erp_name'])
            ->orderByDesc('is_active')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $rules->map(fn (CrmTaskRecurrence $rule): array => $this->payload($rule))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);
        $data = $this->validated($request);

        $rule = CrmTaskRecurrence::create([
            ...$data,
            'author_id' => $actor->getKey(),
        ]);

        // Создаём вхождения сразу: правило, заведённое утром на «сегодня в 13:30»,
        // не должно ждать ночного планировщика, иначе первый день пропадёт.
        $this->recurrences->generateFor(
            $rule,
            \Carbon\CarbonImmutable::now()->startOfDay(),
            CrmTaskRecurrenceService::HORIZON_DAYS,
        );

        return response()->json(['data' => $this->payload($rule->fresh())], 201);
    }

    public function update(Request $request, CrmTaskRecurrence $recurrence): JsonResponse
    {
        $this->authorizeRule($request, $recurrence);

        $recurrence->update($this->validated($request));

        return response()->json(['data' => $this->payload($recurrence->fresh())]);
    }

    /**
     * Отмена цепочки.
     *
     * `is_active = false`, а не удаление: уже созданные задачи остаются
     * в истории вместе с отчётами о закрытии. Висящую задачу тоже не трогаем —
     * менеджер закроет или отменит её сам.
     */
    public function destroy(Request $request, CrmTaskRecurrence $recurrence): JsonResponse
    {
        $this->authorizeRule($request, $recurrence);

        $recurrence->update(['is_active' => false]);

        return response()->json(['data' => $this->payload($recurrence->fresh())]);
    }

    private function authorizeRule(Request $request, CrmTaskRecurrence $recurrence): void
    {
        $actor = $this->crmActor($request);

        $mine = (int) $recurrence->author_id === (int) $actor->getKey()
            || (int) $recurrence->assignee_id === (int) $actor->getKey();

        // Правило коллеги правит тот, кто может действовать с чужими записями —
        // та же граница, что у задач.
        abort_unless($mine || $actor->can('crm-department.edit'), 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'assignee_id' => ['required', 'integer', 'exists:users,id'],
            'client_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'priority' => ['sometimes', Rule::enum(\App\Enums\Crm\TaskPriority::class)],
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'due_time' => ['required', 'date_format:H:i'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'related_type' => ['nullable', 'required_with:related_id', Rule::in(CrmEntityMap::taskableTypes())],
            'related_id' => ['nullable', 'required_with:related_type', 'integer', 'min:1'],
        ], [
            'title.required' => 'Введите, что нужно делать.',
            'assignee_id.required' => 'Выберите исполнителя.',
            'weekdays.required' => 'Выберите хотя бы один день недели.',
            'weekdays.min' => 'Выберите хотя бы один день недели.',
            'due_time.required' => 'Укажите время.',
            'due_time.date_format' => 'Время указывается как ЧЧ:ММ, например 13:30.',
            'starts_on.required' => 'Укажите, с какой даты правило действует.',
            'ends_on.after_or_equal' => 'Дата окончания не может быть раньше даты начала.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(CrmTaskRecurrence $rule): array
    {
        return [
            'id' => (int) $rule->getKey(),
            'title' => $rule->title,
            'description' => $rule->description,
            'schedule_label' => $rule->scheduleLabel(),
            'weekdays' => array_map('intval', $rule->weekdays ?? []),
            'due_time' => substr((string) $rule->due_time, 0, 5),
            'starts_on' => $rule->starts_on?->toDateString(),
            'ends_on' => $rule->ends_on?->toDateString(),
            'is_active' => (bool) $rule->is_active,
            'assignee' => $rule->assignee === null ? null : [
                'id' => (int) $rule->assignee->getKey(),
                'name' => $rule->assignee->name,
            ],
            'client' => $rule->client === null ? null : [
                'id' => (int) $rule->client->getKey(),
                'name' => $rule->client->display_name,
            ],
        ];
    }
}
