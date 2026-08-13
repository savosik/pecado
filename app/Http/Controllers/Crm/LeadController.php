<?php

namespace App\Http\Controllers\Crm;

use App\Enums\Crm\CrmScope;
use App\Models\CrmLead;
use App\Models\CrmLeadStage;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Crm\ClientRowEnricher;
use App\Services\Crm\CrmLeadService;
use App\Services\Crm\LeadFunnelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Лиды: доска, карточка, перемещение по воронке (crm-26, crm-27).
 *
 * Доска отдаётся Inertia целиком, а перемещение — JSON: перетаскивание карточки
 * не должно перезагружать страницу, иначе на доске в сотню лидов каждый сдвиг
 * возвращал бы пользователя к началу.
 */
class LeadController extends CrmController
{
    public function __construct(
        private readonly CrmLeadService $leads,
        private readonly LeadFunnelService $funnel,
        private readonly ClientRowEnricher $rows,
    ) {}

    /**
     * Сортировки таблицы. Ключ — то, что приходит из UI, значение — колонка.
     */
    private const SORTABLE = [
        'name' => 'name',
        'qualified_amount' => 'qualified_amount',
        'expected_close_at' => 'expected_close_at',
        'stage_changed_at' => 'stage_changed_at',
        'created_at' => 'created_at',
    ];

    public function index(Request $request): Response
    {
        $actor = $this->crmActor($request);
        $scope = CrmScope::fromRequest($request, $actor);

        // Доска отдаётся целиком — карточки должны разложиться по колонкам, и
        // «страница 2» на канбане смысла не имеет. Таблица, наоборот, обязана
        // быть постраничной: в ней разбирают всю базу лидов сразу.
        $table = $request->input('view') === 'table';

        $stages = CrmLeadStage::query()->onBoard()->get();
        $query = $this->visibleQuery($request, $actor, $scope);

        return Inertia::render('Crm/Pages/Leads/Index', [
            'stages' => $stages->map(fn (CrmLeadStage $stage): array => $this->stagePayload($stage))->all(),
            'leads' => $table ? [] : $query->orderByDesc('id')->get()
                ->map(fn (CrmLead $lead): array => $this->payload($lead))->all(),
            'rows' => $table ? $this->tableRows($request, $actor, $query) : null,
            'funnel' => $this->funnel->summary($actor, $scope),
            'managers' => $actor->can('crm-department.edit')
                ? PersonalManager::query()->active()->select('id', 'name')->orderBy('name')->get()
                : [],
            // Своя карточка менеджера — чтобы рядовой сотрудник мог забрать
            // ничьего лида себе, не получая списка всего отдела.
            'currentManagerId' => $actor->managerProfile?->id,
            // Ссылка из ленты и списка задач открывает карточку сразу.
            'openLeadId' => $request->filled('lead') ? (int) $request->input('lead') : null,
            'sources' => $this->sources($actor),
            'filters' => [
                'scope' => $scope->value,
                'search' => $request->input('search'),
                'view' => $table ? 'table' : 'board',
                'manager_id' => $request->input('manager_id'),
                'stage_id' => $request->input('stage_id'),
                'source' => $request->input('source'),
                'stale' => $request->boolean('stale') ?: null,
                'sort' => $request->input('sort'),
                'direction' => $request->input('direction'),
            ],
            'staleDays' => CrmLead::STALE_DAYS,
            'canSeeDepartment' => $this->seesDepartment($request),
            'canEdit' => $actor->can('crm-leads.edit'),
            'canCreate' => $actor->can('crm-leads.create'),
            'canDelete' => $actor->can('crm-leads.delete'),
            'canManageStages' => $actor->can('crm-lead-stages.edit'),
        ]);
    }

    /**
     * Лиды, видимые актору, с наложенными фильтрами — общая основа доски и таблицы.
     *
     * @return \Illuminate\Database\Eloquent\Builder<CrmLead>
     */
    private function visibleQuery(Request $request, User $actor, CrmScope $scope)
    {
        return CrmLead::query()
            ->visibleTo($actor, $scope)
            ->with(['manager:id,name', 'stage:id,name,color', 'convertedUser:id,name,erp_name'])
            ->when($request->filled('search'), fn ($query) => $query->where(function ($inner) use ($request) {
                $like = '%'.trim((string) $request->input('search')).'%';
                $inner->where('name', 'like', $like)
                    ->orWhere('company_name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('email', 'like', $like);
            }))
            ->when($request->filled('manager_id'), fn ($query) => $query
                ->where('manager_id', (int) $request->input('manager_id')))
            ->when($request->filled('stage_id'), fn ($query) => $query
                ->where('stage_id', (int) $request->input('stage_id')))
            ->when($request->filled('source'), fn ($query) => $query
                ->where('source', $request->input('source')))
            ->when($request->boolean('stale'), fn ($query) => $query->stagnant());
    }

    /**
     * Страница таблицы вместе с ячейкой задач.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<CrmLead>  $query
     * @return array<string, mixed>
     */
    private function tableRows(Request $request, User $actor, $query): array
    {
        $sort = self::SORTABLE[$request->input('sort')] ?? null;
        $direction = $request->input('direction') === 'asc' ? 'asc' : 'desc';

        $leads = $query
            ->when($sort !== null, fn ($inner) => $inner->orderBy($sort, $direction))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        // Задачи одним запросом на страницу: у лида нет client_user_id, связь
        // держится на related_type/related_id.
        $tasks = $this->rows->tasksForRelated(
            CrmLead::class,
            $leads->getCollection()->map(fn (CrmLead $lead): int => (int) $lead->getKey())->all(),
            $actor,
        );

        $leads->getCollection()->transform(fn (CrmLead $lead): array => [
            ...$this->payload($lead),
            'tasks' => $tasks[(int) $lead->getKey()] ?? ['active_count' => 0, 'next' => null],
        ]);

        return $leads->toArray();
    }

    /**
     * Источники, которые реально встречаются у видимых лидов — для фильтра.
     *
     * @return list<string>
     */
    private function sources(User $actor): array
    {
        return CrmLead::query()
            ->visibleTo($actor)
            ->whereNotNull('source')
            ->where('source', '!=', '')
            ->distinct()
            ->orderBy('source')
            ->pluck('source')
            ->all();
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);
        $data = $this->assertManagerAllowed($actor, $this->validated($request));

        $lead = $this->leads->create($data, $actor);

        return response()->json(['data' => $this->payload($lead->fresh())], 201);
    }

    public function update(Request $request, CrmLead $lead): JsonResponse
    {
        $this->authorizeLead($request, $lead);

        $lead->update($this->assertManagerAllowed($this->crmActor($request), $this->validated($request)));

        return response()->json(['data' => $this->payload($lead->fresh())]);
    }

    /**
     * Кому сотрудник вправе назначить лида.
     *
     * Без права на отдел выбор сводится к «себе или никому»: иначе менеджер
     * перекидывал бы лидов коллегам, а список менеджеров ему и не отдаётся —
     * то есть значение могло прийти только в обход интерфейса.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function assertManagerAllowed(User $actor, array $data): array
    {
        if (! array_key_exists('manager_id', $data) || $data['manager_id'] === null) {
            return $data;
        }

        if ($actor->can('crm-department.edit')) {
            return $data;
        }

        if ((int) $data['manager_id'] !== (int) $actor->managerProfile?->id) {
            throw ValidationException::withMessages([
                'manager_id' => 'Назначить лида другому менеджеру может только руководитель.',
            ]);
        }

        return $data;
    }

    /**
     * Перетаскивание карточки на доске.
     */
    public function move(Request $request, CrmLead $lead): JsonResponse
    {
        $this->authorizeLead($request, $lead);

        $validated = $request->validate([
            'stage_id' => ['required', 'integer', Rule::exists('crm_lead_stages', 'id')],
        ], [
            'stage_id.required' => 'Не указана стадия.',
            'stage_id.exists' => 'Такой стадии нет.',
        ]);

        $stage = CrmLeadStage::query()->findOrFail($validated['stage_id']);

        $this->leads->moveToStage($lead, $stage, $this->crmActor($request));

        return response()->json(['data' => $this->payload($lead->fresh())]);
    }

    /**
     * Привязать лида к появившемуся партнёру.
     */
    public function convert(Request $request, CrmLead $lead): JsonResponse
    {
        $this->authorizeLead($request, $lead);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ], [
            'user_id.required' => 'Выберите партнёра.',
            'user_id.exists' => 'Такого партнёра нет.',
        ]);

        $actor = $this->crmActor($request);

        // Привязать можно только к видимому партнёру: иначе через конверсию
        // открылся бы доступ к чужой карточке в обход скоупа.
        $client = User::query()->visibleInCrm($actor)->findOrFail($validated['user_id']);

        return response()->json(['data' => $this->payload($this->leads->convert($lead, $client, $actor))]);
    }

    /**
     * Разбор пачкой: назначить, перенести, удалить.
     *
     * Недоступные лиды молча пропускаются, а не роняют весь запрос: выделение
     * идёт по видимой странице, и один чужой лид в списке не повод отменять
     * работу с остальными двадцатью. Сколько применилось — в ответе.
     */
    public function bulk(Request $request): JsonResponse
    {
        $actor = $this->crmActor($request);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
            'action' => ['required', 'string', Rule::in(['assign', 'move', 'delete'])],
            'manager_id' => ['nullable', 'integer', 'exists:personal_managers,id'],
            'stage_id' => ['nullable', 'integer', Rule::exists('crm_lead_stages', 'id')],
        ], [
            'ids.required' => 'Не выбрано ни одного лида.',
            'ids.max' => 'За один раз можно обработать не больше 200 лидов.',
            'action.in' => 'Неизвестное действие.',
            'stage_id.exists' => 'Такой стадии нет.',
        ]);

        if ($validated['action'] === 'delete') {
            abort_unless($actor->can('crm-leads.delete'), 403);
        }

        if ($validated['action'] === 'assign') {
            $this->assertManagerAllowed($actor, ['manager_id' => $validated['manager_id'] ?? null]);
        }

        $stage = $validated['action'] === 'move'
            ? CrmLeadStage::query()->findOrFail($validated['stage_id'] ?? 0)
            : null;

        $leads = CrmLead::query()
            ->visibleTo($actor)
            ->whereIn('id', $validated['ids'])
            ->get()
            ->filter(fn (CrmLead $lead): bool => $this->mayAct($actor, $lead));

        foreach ($leads as $lead) {
            match ((string) $validated['action']) {
                // Через сервис, а не update(): иначе журнал переходов и
                // stage_changed_at разъедутся с тем, что пишет доска.
                'move' => $stage === null ? null : $this->leads->moveToStage($lead, $stage, $actor),
                'assign' => $lead->update(['manager_id' => $validated['manager_id'] ?? null]),
                default => $lead->delete(),
            };
        }

        return response()->json(['applied' => $leads->count(), 'requested' => count($validated['ids'])]);
    }

    public function destroy(Request $request, CrmLead $lead): RedirectResponse
    {
        $this->authorizeLead($request, $lead);

        abort_unless($this->crmActor($request)->can('crm-leads.delete'), 403);

        $lead->delete();

        return back();
    }

    /**
     * Лид коллеги правит тот, кто может действовать с чужими записями —
     * та же граница, что у задач.
     */
    private function authorizeLead(Request $request, CrmLead $lead): void
    {
        $actor = $this->crmActor($request);

        // Чужого лида для того, кто не видит отдел, не существует — 404,
        // как и у партнёра.
        abort_unless(
            CrmLead::query()->visibleTo($actor)->whereKey($lead->getKey())->exists(),
            404,
        );

        abort_unless($this->mayAct($actor, $lead), 403);
    }

    /**
     * Вправе ли сотрудник менять этого лида.
     *
     * Отдельно от {@see authorizeLead()}, потому что массовое действие обязано
     * пропустить чужого лида молча, а не ответить 403 на всю пачку.
     */
    private function mayAct(User $actor, CrmLead $lead): bool
    {
        $mine = $lead->manager_id === null
            || (int) $lead->manager_id === (int) $actor->managerProfile?->id;

        return $mine || $actor->can('crm-department.edit');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'messenger' => ['nullable', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:255'],
            'manager_id' => ['nullable', 'integer', 'exists:personal_managers,id'],
            'stage_id' => ['nullable', 'integer', 'exists:crm_lead_stages,id'],
            'qualified_amount' => ['nullable', 'numeric', 'min:0'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'expected_close_at' => ['nullable', 'date'],
            'decision_maker' => ['nullable', 'string', 'max:255'],
            'interests' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:20000'],
            'lost_reason' => ['nullable', 'string', 'max:255'],
        ], [
            'name.required' => 'Введите имя или название организации.',
            'email.email' => 'Проверьте адрес электронной почты.',
        ]);

        // Минимум лида — имя и любой контакт. Требовать конкретный означало бы
        // не дать завести лида, у которого есть только телефон.
        if (blank($data['phone'] ?? null) && blank($data['email'] ?? null) && blank($data['messenger'] ?? null)) {
            throw ValidationException::withMessages([
                'phone' => 'Укажите хотя бы один контакт: телефон, email или мессенджер.',
            ]);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function stagePayload(CrmLeadStage $stage): array
    {
        return [
            'id' => (int) $stage->getKey(),
            'name' => $stage->name,
            'color' => $stage->color,
            'position' => $stage->position,
            'is_won' => $stage->is_won,
            'is_lost' => $stage->is_lost,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(CrmLead $lead): array
    {
        return [
            'id' => (int) $lead->getKey(),
            'name' => $lead->name,
            'company_name' => $lead->company_name,
            'contact' => $lead->primaryContact(),
            'phone' => $lead->phone,
            'email' => $lead->email,
            'messenger' => $lead->messenger,
            'source' => $lead->source,
            'stage_id' => $lead->stage_id === null ? null : (int) $lead->stage_id,
            // Стадия объектом нужна таблице: там нет колонок доски, из которых
            // можно было бы взять название и цвет по одному stage_id.
            'stage' => $lead->stage === null ? null : [
                'id' => (int) $lead->stage->getKey(),
                'name' => $lead->stage->name,
                'color' => $lead->stage->color,
            ],
            'manager' => $lead->manager === null ? null : [
                'id' => (int) $lead->manager->getKey(),
                'name' => $lead->manager->name,
            ],
            'qualified_amount' => $lead->qualified_amount === null ? null : (float) $lead->qualified_amount,
            'currency_code' => $lead->currency_code,
            'expected_close_at' => $lead->expected_close_at?->toDateString(),
            'decision_maker' => $lead->decision_maker,
            'interests' => $lead->interests,
            'notes' => $lead->notes,
            'lost_reason' => $lead->lost_reason,
            'days_on_stage' => $lead->daysOnStage(),
            'converted_user' => $lead->convertedUser === null ? null : [
                'id' => (int) $lead->convertedUser->getKey(),
                'name' => $lead->convertedUser->display_name,
            ],
            'created_at' => $lead->created_at?->format('d.m.Y'),
        ];
    }
}
