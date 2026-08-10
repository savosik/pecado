<?php

namespace App\Http\Controllers\Crm;

use App\Enums\Crm\TaskPriority;
use App\Enums\Crm\TaskStatus;
use App\Http\Requests\Crm\CloseCrmTaskRequest;
use App\Http\Requests\Crm\StoreCrmTaskRequest;
use App\Http\Requests\Crm\UpdateCrmTaskRequest;
use App\Models\Company;
use App\Models\CrmTask;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Crm\CrmEntityResolver;
use App\Services\Crm\CrmTaskService;
use App\Support\Crm\CrmAttachments;
use App\Support\Crm\CrmEntityMap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Задачи менеджеров.
 *
 * Раздел «Задачи» отдаётся Inertia-страницей, всё остальное — JSON: тот же диалог
 * и та же врезка встраиваются в карточку партнёра и в админские карточки заказа
 * и реализации, где Inertia-редирект увёл бы пользователя со страницы.
 */
class TaskController extends CrmController
{
    public function __construct(
        private readonly CrmTaskService $tasks,
        private readonly CrmEntityResolver $resolver,
    ) {}

    /**
     * Раздел «Задачи»: полный список с фильтрами.
     *
     * Пресеты («Мне», «От меня», «Просрочено», «Без привязки») — это фильтры поверх
     * одного списка, а не отдельные его версии: иначе задача без привязки или
     * поставленная третьим лицом терялась бы между вкладками.
     */
    public function index(Request $request): Response
    {
        $actor = $this->crmActor($request);
        Gate::authorize('viewAny', CrmTask::class);

        $filters = $this->validateFilters($request);

        $query = $this->tasks->visibleTo($actor)
            ->with(['author:id,name', 'assignee:id,name', 'related'])
            ->withCount(['media as attachments_count' => fn ($media) => $media->where(
                'collection_name',
                CrmAttachments::COLLECTION,
            )]);

        $this->applyFilters($query, $filters, $actor);
        $this->applySort($query, $filters);

        $paginator = $query->paginate($filters['per_page'])->withQueryString();

        return Inertia::render('Crm/Pages/Tasks/Index', [
            'tasks' => $paginator->through(fn (CrmTask $task) => $this->tasks->payload($task, $actor)),
            'filters' => $filters,
            'counters' => $this->counters($actor),
            'options' => $this->optionsPayload($actor),
            // Ссылка из ленты партнёра ведёт сюда с ?task=ID — список откроет карточку.
            'openTaskId' => $request->integer('task') ?: null,
        ]);
    }

    /**
     * Задачи одной сущности — для врезки в её карточке.
     */
    public function list(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);
        Gate::authorize('viewAny', CrmTask::class);

        $validated = $request->validate([
            'entity_type' => ['required', 'string', Rule::in(CrmEntityMap::taskableTypes())],
            'entity_id' => ['required', 'integer', 'min:1'],
        ]);

        $entity = $this->resolver->resolveForActor(
            $actor,
            $validated['entity_type'],
            (int) $validated['entity_id'],
        );

        $paginator = $this->tasks->visibleTo($actor)
            ->where('related_type', $entity::class)
            ->where('related_id', $entity->getKey())
            ->with(['author:id,name', 'assignee:id,name', 'related'])
            ->withCount(['media as attachments_count' => fn ($media) => $media->where(
                'collection_name',
                CrmAttachments::COLLECTION,
            )])
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->orderByDesc('id')
            ->paginate(30);

        return response()->json(
            $paginator->through(fn (CrmTask $task) => $this->tasks->payload($task, $actor))
        );
    }

    /**
     * Справочники для диалога задачи: исполнители, статусы, приоритеты.
     *
     * Отдельным эндпоинтом, потому что диалог открывается и на страницах админки,
     * где Inertia-пропсов CRM нет.
     */
    public function options(Request $request): JsonResponse
    {
        return response()->json($this->optionsPayload($this->crmActor($request)));
    }

    /**
     * Поиск сущности для привязки задачи — партнёры, заказы, реализации.
     *
     * Выдача ограничена скоупом актора тем же способом, что и всё остальное в CRM:
     * иначе подсказка в диалоге стала бы способом перечислить чужих партнёров.
     */
    public function entities(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);
        Gate::authorize('viewAny', CrmTask::class);

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(CrmEntityMap::taskableTypes())],
            'query' => ['nullable', 'string', 'max:255'],
        ]);

        $search = trim((string) ($validated['query'] ?? ''));

        return response()->json(match ($validated['type']) {
            CrmEntityMap::CLIENT => $this->searchClients($actor, $search),
            CrmEntityMap::CONTRACTOR => $actor->can('crm-contractors.view')
                ? $this->searchContractors($actor, $search)
                : [],
            CrmEntityMap::ORDER => $this->searchDocuments($actor, Order::class, $search, 'Заказ'),
            CrmEntityMap::SHIPMENT => $this->searchDocuments($actor, Shipment::class, $search, 'Реализация'),
            default => [],
        });
    }

    /**
     * @return list<array{id: int, label: string, sublabel: string|null}>
     */
    private function searchClients(User $actor, string $search): array
    {
        return User::query()
            ->visibleInCrm($actor)
            ->select('id', 'name', 'erp_name', 'email')
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('name', 'like', "%{$search}%")
                ->orWhere('erp_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->orderByRaw("COALESCE(NULLIF(erp_name, ''), name)")
            ->take(20)
            ->get()
            ->map(fn (User $client): array => [
                'id' => (int) $client->getKey(),
                'label' => (string) $client->display_name,
                'sublabel' => $client->email,
            ])
            ->all();
    }

    /**
     * Контрагенты — по наименованию, юрнаименованию и ИНН.
     *
     * @return list<array{id: int, label: string, sublabel: string|null}>
     */
    private function searchContractors(User $actor, string $search): array
    {
        return Company::query()
            ->visibleInCrm($actor)
            ->select('id', 'user_id', 'name', 'legal_name', 'tax_id')
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('name', 'like', "%{$search}%")
                ->orWhere('legal_name', 'like', "%{$search}%")
                ->orWhere('tax_id', 'like', "%{$search}%")))
            ->with('user:id,name,erp_name')
            ->orderBy('name')
            ->take(20)
            ->get()
            ->map(fn (Company $company): array => [
                'id' => (int) $company->getKey(),
                'label' => (string) ($company->name ?: $company->legal_name ?: 'Контрагент №'.$company->getKey()),
                // Партнёр в подписи важнее ИНН: одноимённые юрлица у разных
                // партнёров в выдаче иначе неразличимы.
                'sublabel' => $company->user instanceof User
                    ? (string) $company->user->display_name
                    : ($company->tax_id === null ? null : 'ИНН '.$company->tax_id),
            ])
            ->all();
    }

    /**
     * Заказы и реализации ищутся одинаково — по номеру, местному и из 1С.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @return list<array{id: int, label: string, sublabel: string|null}>
     */
    private function searchDocuments(User $actor, string $modelClass, string $search, string $label): array
    {
        return $modelClass::query()
            // Документ без партнёра (партнёрский из 1С) доступен только тем, кто видит
            // весь отдел, — то же правило, что в CrmEntityResolver::canAccess().
            ->when(
                ! $actor->can('crm-clients-all.view'),
                fn (Builder $query) => $query->whereIn(
                    'user_id',
                    User::query()->visibleInCrm($actor)->select('id'),
                ),
            )
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('number', 'like', "%{$search}%")
                ->orWhere('erp_number', 'like', "%{$search}%")))
            ->with('user:id,name,erp_name')
            ->latest('id')
            ->take(20)
            ->get()
            // Через getAttribute(), а не свойствами: класс здесь обобщённый
            // (заказ или реализация), и статический анализ его полей не знает.
            ->map(function (Model $document) use ($label): array {
                $client = $document->getAttribute('user');

                return [
                    'id' => (int) $document->getKey(),
                    'label' => $label.' №'.($document->getAttribute('number') ?: $document->getKey()),
                    'sublabel' => $client instanceof User ? (string) $client->display_name : null,
                ];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function optionsPayload(User $actor): array
    {
        return [
            'assignees' => $this->tasks->assignableUsers(),
            'statuses' => TaskStatus::optionsWithColor(),
            'priorities' => TaskPriority::optionsWithColor(),
            'entity_types' => array_map(
                fn (string $type): array => ['value' => $type, 'label' => CrmEntityMap::labelFor($type)],
                $this->taskableTypesFor($actor),
            ),
        ];
    }

    /**
     * Типы привязки, доступные актору.
     *
     * Контрагенты — отдельный раздел со своим правом: без него тип в диалоге
     * не показываем, иначе выбор молча упирался бы в 404 от резолвера.
     *
     * @return list<string>
     */
    private function taskableTypesFor(User $actor): array
    {
        $types = CrmEntityMap::taskableTypes();

        if ($actor->can('crm-contractors.view')) {
            return $types;
        }

        return array_values(array_diff($types, [CrmEntityMap::CONTRACTOR]));
    }

    public function show(Request $request, CrmTask $task): JsonResponse
    {
        $actor = $this->crmActor($request);
        Gate::authorize('view', $task);

        $task->load(['author:id,name', 'assignee:id,name', 'related']);

        return response()->json($this->tasks->payload($task, $actor));
    }

    public function store(StoreCrmTaskRequest $request): JsonResponse
    {
        $actor = $this->crmActor($request);

        // Доступ к сущности проверяется до создания: иначе задача стала бы способом
        // узнать о существовании чужого партнёра и его документов.
        $related = $request->filled('entity_type')
            ? $this->resolver->resolveForActor(
                $actor,
                $request->string('entity_type')->value(),
                (int) $request->integer('entity_id'),
            )
            : null;

        $task = $this->tasks->create($actor, $request->validated(), $related);
        $task->load(['author:id,name', 'assignee:id,name', 'related']);

        return response()->json($this->tasks->payload($task, $actor), 201);
    }

    public function update(UpdateCrmTaskRequest $request, CrmTask $task): JsonResponse
    {
        $actor = $this->crmActor($request);
        Gate::authorize('update', $task);

        $this->tasks->update($task, $request->validated(), $actor);
        $task->load(['author:id,name', 'assignee:id,name', 'related']);

        return response()->json($this->tasks->payload($task, $actor));
    }

    /**
     * Закрыть задачу с отчётом и следующим шагом.
     *
     * Отдельным эндпоинтом, а не статусом через PATCH: закрытие — это три записи
     * в одной транзакции (отметка, комментарий, follow-up), и разложить их
     * по трём запросам с фронта значило бы допустить закрытие без комментария,
     * который пользователь всё-таки написал.
     */
    public function close(CloseCrmTaskRequest $request, CrmTask $task): JsonResponse
    {
        $actor = $this->crmActor($request);
        Gate::authorize('update', $task);

        $result = $this->tasks->close(
            $task,
            $actor,
            $request->input('comment'),
            $request->input('follow_up'),
        );

        $task->load(['author:id,name', 'assignee:id,name', 'related']);
        $followUp = $result['follow_up'];
        $followUp?->load(['author:id,name', 'assignee:id,name', 'related']);

        return response()->json([
            'task' => $this->tasks->payload($task, $actor),
            'follow_up' => $followUp === null ? null : $this->tasks->payload($followUp, $actor),
        ]);
    }

    public function destroy(Request $request, CrmTask $task): JsonResponse
    {
        Gate::authorize('delete', $task);

        $task->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Счётчики пресетов — они же подписи на кнопках фильтров.
     *
     * @return array<string, int>
     */
    private function counters(User $actor): array
    {
        $actorId = (int) $actor->getKey();
        $active = TaskStatus::activeValues();

        return [
            'mine' => $this->tasks->visibleTo($actor)->assignedTo($actorId)->whereIn('status', $active)->count(),
            'authored' => $this->tasks->visibleTo($actor)->authoredBy($actorId)->whereIn('status', $active)->count(),
            'overdue' => $this->tasks->visibleTo($actor)->assignedTo($actorId)->overdue()->count(),
            'today' => $this->tasks->visibleTo($actor)->assignedTo($actorId)->dueToday()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateFilters(Request $request): array
    {
        $validated = $request->validate([
            'preset' => ['nullable', Rule::in(['mine', 'authored', 'overdue', 'unlinked', 'all'])],
            'status' => ['nullable', Rule::enum(TaskStatus::class)],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'assignee_id' => ['nullable', 'integer'],
            'author_id' => ['nullable', 'integer'],
            'client_id' => ['nullable', 'integer'],
            'entity_type' => ['nullable', Rule::in(CrmEntityMap::taskableTypes())],
            'due' => ['nullable', Rule::in(['overdue', 'today', 'week', 'none'])],
            'search' => ['nullable', 'string', 'max:255'],
            'sort_by' => ['nullable', Rule::in(['due_at', 'created_at', 'priority'])],
            'sort_order' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer'],
        ]);

        return [
            'preset' => $validated['preset'] ?? 'mine',
            'status' => $validated['status'] ?? null,
            'priority' => $validated['priority'] ?? null,
            'assignee_id' => $validated['assignee_id'] ?? null,
            'author_id' => $validated['author_id'] ?? null,
            'client_id' => $validated['client_id'] ?? null,
            'entity_type' => $validated['entity_type'] ?? null,
            'due' => $validated['due'] ?? null,
            'search' => $validated['search'] ?? null,
            'sort_by' => $validated['sort_by'] ?? 'due_at',
            'sort_order' => $validated['sort_order'] ?? 'asc',
            'per_page' => min(max((int) ($validated['per_page'] ?? 20), 5), 100),
        ];
    }

    /**
     * @param  Builder<CrmTask>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters, User $actor): void
    {
        $actorId = (int) $actor->getKey();

        match ($filters['preset']) {
            'mine' => $query->assignedTo($actorId)->whereIn('status', TaskStatus::activeValues()),
            'authored' => $query->authoredBy($actorId)->whereIn('status', TaskStatus::activeValues()),
            'overdue' => $query->overdue(),
            'unlinked' => $query->whereNull('related_type'),
            default => $query,
        };

        if ($filters['status'] !== null) {
            $query->where('status', $filters['status']);
        }

        if ($filters['priority'] !== null) {
            $query->where('priority', $filters['priority']);
        }

        foreach (['assignee_id', 'author_id', 'client_id'] as $field) {
            if ($filters[$field] !== null) {
                $column = $field === 'client_id' ? 'client_user_id' : $field;
                $query->where($column, $filters[$field]);
            }
        }

        if ($filters['entity_type'] !== null) {
            $query->where('related_type', CrmEntityMap::modelClass($filters['entity_type']));
        }

        match ($filters['due']) {
            'overdue' => $query->overdue(),
            'today' => $query->dueToday(),
            'week' => $query->whereNotNull('due_at')->whereBetween('due_at', [now()->startOfDay(), now()->addWeek()]),
            'none' => $query->whereNull('due_at'),
            default => $query,
        };

        if ($filters['search'] !== null && $filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(fn (Builder $inner) => $inner
                ->where('title', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%"));
        }
    }

    /**
     * @param  Builder<CrmTask>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applySort(Builder $query, array $filters): void
    {
        $direction = $filters['sort_order'] === 'desc' ? 'desc' : 'asc';

        if ($filters['sort_by'] === 'priority') {
            // В БД приоритет — строка, и алфавитный порядок ('high','low','normal')
            // смысла не имеет. Порядок задаём явно, одинаково для MySQL и SQLite.
            $query->orderByRaw("case priority when 'high' then 3 when 'normal' then 2 else 1 end {$direction}");
        } elseif ($filters['sort_by'] === 'created_at') {
            $query->orderBy('created_at', $direction);
        } else {
            // Задачи без срока — в конец: иначе они заняли бы весь верх списка,
            // потому что и MySQL, и SQLite сортируют NULL первыми.
            $query->orderByRaw('due_at is null')->orderBy('due_at', $direction);
        }

        $query->orderByDesc('id');
    }
}
