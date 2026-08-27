<?php

namespace App\Http\Controllers\Crm;

use App\Enums\Crm\ContractForm;
use App\Enums\Crm\ContractPaymentTerms;
use App\Enums\Crm\ContractStatus;
use App\Enums\Crm\CrmScope;
use App\Http\Requests\Crm\StoreContractRequest;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Crm\ContractGapService;
use App\Services\Crm\ContractListService;
use App\Services\Crm\CrmEntitySearch;
use App\Support\Crm\CrmEntityMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Реестр договоров.
 *
 * Перенос Google-таблицы менеджеров: вкладки стали категориями, строки —
 * договорами с контрагентом, ответственным, статусом подписания и сроком.
 * Сверх таблицы: сканы, задачи, комментарии, видимость партнёру в кабинете
 * и вкладка «Без договора».
 *
 * Чужой договор отвечает 404, а не 403: 403 подтвердил бы его существование.
 */
class ContractController extends CrmController
{
    public function __construct(
        private readonly ContractListService $contracts,
        private readonly ContractGapService $gaps,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->crmActor($request);
        Gate::authorize('viewAny', Contract::class);

        $filters = $this->validateFilters($request);
        $scope = CrmScope::fromRequest($request, $actor);

        return Inertia::render('Crm/Pages/Contracts/Index', [
            'contracts' => $this->contracts->paginate($actor, [...$filters, 'scope' => $scope]),
            'filters' => [...$filters, 'scope' => $scope->value],
            'categories' => $this->contracts->categories($actor, $scope, withInactive: $actor->can('crm-contracts.edit')),
            'missingCount' => $this->gaps->count($actor, $scope),
            'preselect' => $this->preselect($request, $actor),
            ...$this->sharedProps($request),
        ]);
    }

    /**
     * Предзаполнение формы по ссылке «Завести договор» из карточки партнёра,
     * контрагента или вкладки «Без договора»: `?create=1&company_id=…`.
     * Чужой id молча не подставляется — скоуп тот же, что у формы.
     *
     * @return array<string, mixed>
     */
    private function preselect(Request $request, User $actor): array
    {
        $company = $request->filled('company_id')
            ? Company::query()->visibleInCrm($actor)->select('id', 'name', 'legal_name')->find((int) $request->input('company_id'))
            : null;
        $client = $request->filled('client_id')
            ? User::query()->visibleInCrm($actor)->select('id', 'name', 'erp_name')->find((int) $request->input('client_id'))
            : null;

        return [
            'create' => $request->boolean('create'),
            'company' => $company === null ? null : [
                'id' => (int) $company->getKey(),
                'name' => (string) ($company->name ?: $company->legal_name),
            ],
            'client' => $client === null ? null : [
                'id' => (int) $client->getKey(),
                'name' => (string) $client->display_name,
            ],
        ];
    }

    /**
     * Вкладка «Без договора»: контрагенты с реализацией или заказом без
     * действующего договора в реестре.
     */
    public function missing(Request $request): Response
    {
        $actor = $this->crmActor($request);
        Gate::authorize('viewAny', Contract::class);

        $scope = CrmScope::fromRequest($request, $actor);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'kind' => ['nullable', Rule::in([ContractGapService::KIND_SHIPMENTS, ContractGapService::KIND_ORDERS])],
            'manager_id' => ['nullable', 'integer', 'min:1'],
            'sort_by' => ['nullable', Rule::in(['name', 'shipments_total', 'shipments_count', 'last_shipment', 'orders_count'])],
            'sort_order' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ], [], [
            'search' => 'поиск',
            'kind' => 'признак',
            'manager_id' => 'менеджер',
            'sort_by' => 'сортировка',
            'sort_order' => 'направление сортировки',
            'per_page' => 'размер страницы',
        ]);

        $filters = [
            'search' => $validated['search'] ?? null,
            'kind' => $validated['kind'] ?? null,
            'manager_id' => $this->seesManagerBreakdown($request) ? ($validated['manager_id'] ?? null) : null,
            'sort_by' => $validated['sort_by'] ?? 'shipments_total',
            'sort_order' => $validated['sort_order'] ?? 'desc',
            'per_page' => $validated['per_page'] ?? 25,
        ];

        return Inertia::render('Crm/Pages/Contracts/Missing', [
            'gaps' => $this->gaps->paginate($actor, [...$filters, 'scope' => $scope]),
            'filters' => [...$filters, 'scope' => $scope->value],
            'categories' => $this->contracts->categories($actor, $scope),
            'missingCount' => $this->gaps->count($actor, $scope),
            ...$this->sharedProps($request),
        ]);
    }

    public function show(Request $request, Contract $contract): Response
    {
        $actor = $this->crmActor($request);
        $this->assertVisible($actor, $contract);

        $contract->load([
            'category:id,name',
            'user:id,name,erp_name,email',
            'company:id,user_id,name,legal_name,tax_id,tax_code',
            'responsibleManager:id,name',
            'createdBy:id,name',
        ]);

        return Inertia::render('Crm/Pages/Contracts/Show', [
            'contract' => $this->payload($contract),
            'categories' => $this->contracts->categories($actor, CrmScope::DEPARTMENT, withInactive: true),
            ...$this->sharedProps($request),
        ]);
    }

    public function store(StoreContractRequest $request): JsonResponse
    {
        $actor = $this->crmActor($request);
        Gate::authorize('create', Contract::class);

        $contract = new Contract($this->attributes($request->validated(), $actor));
        $contract->created_by_user_id = $actor->getKey();
        $contract->updated_by_user_id = $actor->getKey();
        $contract->save();

        return response()->json($this->payload($contract->fresh([
            'category:id,name', 'user:id,name,erp_name,email', 'company:id,user_id,name,legal_name,tax_id,tax_code',
            'responsibleManager:id,name', 'createdBy:id,name',
        ])), 201);
    }

    public function update(StoreContractRequest $request, Contract $contract): JsonResponse
    {
        $actor = $this->crmActor($request);
        $this->assertVisible($actor, $contract);
        Gate::authorize('update', $contract);

        $contract->fill($this->attributes($request->validated(), $actor));
        $contract->updated_by_user_id = $actor->getKey();
        $contract->save();

        return response()->json($this->payload($contract->fresh([
            'category:id,name', 'user:id,name,erp_name,email', 'company:id,user_id,name,legal_name,tax_id,tax_code',
            'responsibleManager:id,name', 'createdBy:id,name',
        ])));
    }

    /**
     * Быстрая правка из строки реестра: статус, оплата, форма, категория,
     * ответственный — по одному полю, без полной формы.
     *
     * Отдельный метод, а не update(): у полной формы `number` и `category_id`
     * обязательны, и одиночный PATCH статуса упирался бы в «укажите номер».
     * Статус «подписан» без даты подписания получает сегодняшнюю: в таблице
     * менеджеров дата подписания не велась вовсе, а пустая колонка у подписанного
     * договора читается как «не подписан».
     */
    public function quick(Request $request, Contract $contract): JsonResponse
    {
        $actor = $this->crmActor($request);
        $this->assertVisible($actor, $contract);
        Gate::authorize('update', $contract);

        $validated = $request->validate([
            'status' => ['sometimes', 'required', Rule::enum(ContractStatus::class)],
            'payment_terms' => ['sometimes', 'nullable', Rule::enum(ContractPaymentTerms::class)],
            'form' => ['sometimes', 'nullable', Rule::enum(ContractForm::class)],
            'category_id' => ['sometimes', 'required', 'integer', 'exists:contract_categories,id'],
            'responsible_manager_id' => ['sometimes', 'nullable', 'integer', 'exists:personal_managers,id'],
        ], [
            'status.required' => 'Укажите статус подписания.',
            'category_id.required' => 'Выберите категорию.',
            'category_id.exists' => 'Такой категории нет.',
            'responsible_manager_id.exists' => 'Такого менеджера нет.',
        ]);

        if ($validated === []) {
            throw ValidationException::withMessages(['status' => 'Нечего менять.']);
        }

        $contract->fill($validated);

        if ($contract->status === ContractStatus::SIGNED && $contract->signed_at === null) {
            $contract->signed_at = now()->toDateString();
        }

        $contract->updated_by_user_id = $actor->getKey();
        $contract->save();

        return response()->json($this->payload($contract->fresh([
            'category:id,name', 'user:id,name,erp_name,email', 'company:id,user_id,name,legal_name,tax_id,tax_code',
            'responsibleManager:id,name', 'createdBy:id,name',
        ])));
    }

    public function destroy(Request $request, Contract $contract): JsonResponse
    {
        $actor = $this->crmActor($request);
        $this->assertVisible($actor, $contract);
        Gate::authorize('delete', $contract);

        $contract->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Поиск партнёра или контрагента для формы — тот же поиск, что у задач,
     * но только по двум типам: остальные к договору не привязываются.
     */
    public function entities(Request $request, CrmEntitySearch $search): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in([CrmEntityMap::CLIENT, CrmEntityMap::CONTRACTOR])],
            'query' => ['nullable', 'string', 'max:255'],
        ], [
            'type.required' => 'Укажите тип записи.',
            'type.in' => 'Договор привязывается только к партнёру или контрагенту.',
        ]);

        return response()->json([
            'data' => $search->search($this->crmActor($request), $validated['type'], (string) ($validated['query'] ?? '')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateFilters(Request $request): array
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::enum(ContractStatus::class)],
            'payment_terms' => ['nullable', Rule::enum(ContractPaymentTerms::class)],
            'form' => ['nullable', Rule::enum(ContractForm::class)],
            'manager_id' => ['nullable', 'integer', 'min:1'],
            'client_id' => ['nullable', 'integer', 'min:1'],
            'company_id' => ['nullable', 'integer', 'min:1'],
            'expiring' => ['nullable', 'boolean'],
            'sort_by' => ['nullable', Rule::in(['date', 'number', 'counterparty', 'status', 'valid_until', 'signed_at'])],
            'sort_order' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ], [], [
            'search' => 'поиск',
            'category_id' => 'категория',
            'status' => 'статус',
            'payment_terms' => 'вариант оплаты',
            'form' => 'форма',
            'manager_id' => 'ответственный',
            'client_id' => 'партнёр',
            'company_id' => 'контрагент',
            'expiring' => 'истекающие',
            'sort_by' => 'сортировка',
            'sort_order' => 'направление сортировки',
            'per_page' => 'размер страницы',
        ]);

        // Булевы фильтры — 1 или null: снимок уезжает в URL и возвращается строкой,
        // а «false» не пройдёт правило boolean.
        return [
            'search' => $validated['search'] ?? null,
            'category_id' => $validated['category_id'] ?? null,
            'status' => $validated['status'] ?? null,
            'payment_terms' => $validated['payment_terms'] ?? null,
            'form' => $validated['form'] ?? null,
            'manager_id' => $validated['manager_id'] ?? null,
            'client_id' => $validated['client_id'] ?? null,
            'company_id' => $validated['company_id'] ?? null,
            'expiring' => ! empty($validated['expiring']) ? 1 : null,
            'sort_by' => $validated['sort_by'] ?? 'date',
            'sort_order' => $validated['sort_order'] ?? 'desc',
            'per_page' => $validated['per_page'] ?? 25,
        ];
    }

    /**
     * Справочники и права — одни на список, вкладку и карточку.
     *
     * @return array<string, mixed>
     */
    private function sharedProps(Request $request): array
    {
        $actor = $this->crmActor($request);

        return [
            'statuses' => ContractStatus::options(),
            'paymentTerms' => ContractPaymentTerms::options(),
            'forms' => ContractForm::options(),
            'managers' => PersonalManager::query()->active()->select('id', 'name')->orderBy('name')->get(),
            'organizations' => Organization::query()->real()->active()->ordered()->get(['id', 'name']),
            'canSeeDepartment' => $this->seesDepartment($request),
            'canFilterByManager' => $this->seesManagerBreakdown($request),
            'expiringDays' => ContractListService::expiringDays(),
            'can' => [
                'create' => $actor->can('crm-contracts.create'),
                'edit' => $actor->can('crm-contracts.edit'),
                'delete' => $actor->can('crm-contracts.delete'),
            ],
        ];
    }

    private function assertVisible(User $actor, Contract $contract): void
    {
        abort_unless($actor->can('view', $contract), 404);
    }

    /**
     * Поля договора из валидированного запроса.
     *
     * Контрагент и партнёр берутся через скоуп актора: чужой id не проходит
     * как «не найден», а не как 403. Партнёр без явного выбора подтянется
     * с контрагента в Contract::booted().
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(array $validated, User $actor): array
    {
        $companyId = $validated['company_id'] ?? null;
        $clientId = $validated['client_id'] ?? null;

        if ($companyId !== null && ! Company::query()->visibleInCrm($actor)->whereKey((int) $companyId)->exists()) {
            throw ValidationException::withMessages(['company_id' => 'Контрагент не найден.']);
        }

        if ($clientId !== null && ! User::query()->visibleInCrm($actor)->whereKey((int) $clientId)->exists()) {
            throw ValidationException::withMessages(['client_id' => 'Партнёр не найден.']);
        }

        return [
            'category_id' => (int) $validated['category_id'],
            'number' => trim((string) $validated['number']),
            'company_id' => $companyId === null ? null : (int) $companyId,
            'user_id' => $clientId === null ? null : (int) $clientId,
            'counterparty_name' => filled($validated['counterparty_name'] ?? null) ? trim((string) $validated['counterparty_name']) : null,
            'date' => $validated['date'] ?? null,
            'signed_at' => $validated['signed_at'] ?? null,
            'valid_from' => $validated['valid_from'] ?? null,
            'valid_until' => $validated['valid_until'] ?? null,
            'status' => $validated['status'],
            'payment_terms' => $validated['payment_terms'] ?? null,
            'form' => $validated['form'] ?? null,
            'responsible_manager_id' => $validated['responsible_manager_id'] ?? null,
            'is_visible_in_cabinet' => (bool) ($validated['is_visible_in_cabinet'] ?? true),
            'comment' => filled($validated['comment'] ?? null) ? $validated['comment'] : null,
        ];
    }

    /**
     * Карточка: строка списка плюс то, что нужно только карточке.
     *
     * @return array<string, mixed>
     */
    private function payload(Contract $contract): array
    {
        return [
            ...$this->contracts->row($contract),
            'company_details' => $contract->company instanceof Company ? [
                'id' => (int) $contract->company->getKey(),
                'name' => (string) ($contract->company->name ?: $contract->company->legal_name),
                'legal_name' => $contract->company->legal_name,
                'tax_id' => $contract->company->tax_id,
                'tax_code' => $contract->company->tax_code ?: null,
                'url' => route('crm.contractors.show', $contract->company->getKey()),
            ] : null,
            'partner_details' => $contract->user instanceof User ? [
                'id' => (int) $contract->user->getKey(),
                'name' => (string) $contract->user->display_name,
                'email' => $contract->user->email,
                'url' => route('crm.clients.show', $contract->user->getKey()),
            ] : null,
            'created_by' => $contract->createdBy?->name,
            'created_at' => $contract->created_at?->format('d.m.Y H:i'),
        ];
    }
}
