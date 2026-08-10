<?php

namespace App\Http\Controllers\Crm;

use App\Models\Company;
use App\Models\Order;
use App\Models\PersonalManager;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Crm\ContractorListService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Контрагенты — юрлица партнёров.
 *
 * Раздел отвечает на вопрос «с кем именно подписаны документы»: реквизиты, долг
 * по данным 1С и переписка по конкретному юрлицу. Планы и их выполнение сюда не
 * приходят — они считаются по партнёру (см. {@see \App\Services\Crm\PlanProgressService}).
 *
 * Границу видимости задаёт Company::visibleInCrm(): менеджер видит юрлица только
 * своих партнёров, контрагента без партнёра — только тот, у кого crm-clients-all.view.
 * Чужой контрагент отвечает 404, а не 403: 403 подтвердил бы его существование.
 */
class ContractorController extends CrmController
{
    /**
     * Сколько документов показывать в карточке.
     *
     * Карточка отвечает на «что было в последнее время», полный журнал живёт
     * в разделах «Заказы» и «Реализации» с фильтрами и выгрузкой.
     */
    private const DOCUMENTS_LIMIT = 20;

    public function index(Request $request, ContractorListService $contractors): Response
    {
        $actor = $this->crmActor($request);
        $seesAll = $this->seesAllClients($request);

        $filters = $this->validateFilters($request, $seesAll);

        return Inertia::render('Crm/Pages/Contractors/Index', [
            'contractors' => $contractors->paginate($actor, $filters),
            'filters' => $filters,
            'canSeeAll' => $seesAll,
            // Список менеджеров нужен только РОПу — для менеджера отбор по
            // менеджерам бессмыслен: в скоупе и так только его партнёры.
            'managers' => $seesAll
                ? PersonalManager::query()->active()->select('id', 'name')->orderBy('name')->get()
                : [],
        ]);
    }

    public function show(Request $request, int $contractor): Response
    {
        $actor = $this->crmActor($request);

        $company = Company::query()
            ->visibleInCrm($actor)
            ->with(['user:id,name,erp_name,email,phone', 'contractorBalance', 'bankAccounts'])
            ->findOrFail($contractor);

        return Inertia::render('Crm/Pages/Contractors/Show', [
            'contractor' => $this->payload($company),
            'documents' => [
                'orders' => $this->documents(Order::class, $company),
                'shipments' => $this->documents(Shipment::class, $company),
            ],
            'canSeeDocuments' => $actor->can('crm-clients.view'),
        ]);
    }

    /**
     * Разбор фильтров списка.
     *
     * `orphans` доступен только видящим отдел целиком: у менеджера контрагенты
     * без партнёра в скоуп не попадают, и фильтр вернул бы пустоту с намёком,
     * что такие записи вообще есть.
     *
     * @return array<string, mixed>
     */
    private function validateFilters(Request $request, bool $seesAll): array
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'client_id' => ['nullable', 'integer', 'min:1'],
            'manager_id' => ['nullable', 'integer', 'min:1'],
            'debt' => ['nullable', Rule::in([ContractorListService::DEBT_OVERDUE, ContractorListService::DEBT_ANY])],
            'orphans' => ['nullable', 'boolean'],
            'sort_by' => ['nullable', Rule::in(['name', 'balance', 'overdue', 'tasks'])],
            'sort_order' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ], [], [
            'search' => 'поиск',
            'client_id' => 'партнёр',
            'manager_id' => 'менеджер',
            'debt' => 'состояние долга',
            'orphans' => 'без партнёра',
            'sort_by' => 'сортировка',
            'sort_order' => 'направление сортировки',
            'per_page' => 'размер страницы',
        ]);

        return [
            'search' => $validated['search'] ?? null,
            'client_id' => $validated['client_id'] ?? null,
            'manager_id' => $seesAll ? ($validated['manager_id'] ?? null) : null,
            'debt' => $validated['debt'] ?? null,
            'orphans' => $seesAll ? (bool) ($validated['orphans'] ?? false) : false,
            'sort_by' => $validated['sort_by'] ?? 'name',
            'sort_order' => $validated['sort_order'] ?? 'asc',
            'per_page' => $validated['per_page'] ?? 25,
        ];
    }

    /**
     * Карточка контрагента: реквизиты, партнёр, долг и банковские счета.
     *
     * @return array<string, mixed>
     */
    private function payload(Company $company): array
    {
        $balance = $company->contractorBalance;

        return [
            'id' => (int) $company->getKey(),
            'name' => (string) ($company->name ?: $company->legal_name ?: 'Контрагент №'.$company->getKey()),
            'legal_name' => $company->legal_name,
            'tax_id' => $company->tax_id,
            'tax_code' => $company->tax_code ?: null,
            'registration_number' => $company->registration_number,
            'okpo_code' => $company->okpo_code ?: null,
            'legal_address' => $company->legal_address,
            'actual_address' => $company->actual_address,
            'phone' => $company->phone,
            'email' => $company->email,
            'country' => $company->country?->value,
            'is_default' => (bool) $company->is_default,
            'erp_id' => $company->erp_id,
            'created_at' => $company->created_at?->format('d.m.Y H:i'),
            'partner' => $company->user instanceof User ? [
                'id' => (int) $company->user->getKey(),
                'name' => (string) $company->user->display_name,
                'email' => $company->user->email,
                'phone' => $company->user->phone,
            ] : null,
            'balance' => $balance === null ? null : [
                'current' => (float) $balance->current_balance,
                'overdue' => (float) $balance->overdue_debt,
                'updated_at' => $balance->balance_erp_updated_at?->format('d.m.Y H:i'),
            ],
            'bank_accounts' => $company->bankAccounts
                ->map(fn ($account): array => [
                    'id' => (int) $account->getKey(),
                    'bank_name' => $account->bank_name,
                    'account_number' => $account->account_number,
                    'bik' => $account->bank_bik,
                    'correspondent_account' => $account->correspondent_account,
                    'is_primary' => (bool) $account->is_primary,
                ])
                ->all(),
        ];
    }

    /**
     * Последние документы контрагента — заказы и реализации ищутся одинаково.
     *
     * @param  class-string<Model>  $modelClass
     * @return list<array<string, mixed>>
     */
    private function documents(string $modelClass, Company $company): array
    {
        $isOrder = $modelClass === Order::class;

        return $modelClass::query()
            ->where('company_id', $company->getKey())
            ->latest('id')
            ->take(self::DOCUMENTS_LIMIT)
            ->get()
            // Через getAttribute(): класс здесь обобщённый, и статический анализ
            // его полей не знает.
            ->map(fn (Model $document): array => [
                'id' => (int) $document->getKey(),
                'number' => (string) ($document->getAttribute('number') ?: $document->getKey()),
                'erp_number' => $document->getAttribute('erp_number'),
                // Бизнес-дата 1С, а не дата записи в БД: историю прод импортировал
                // разом, и created_at у старых документов ничего не значит.
                'date' => ($document->getAttribute('erp_created_at')
                    ?? $document->getAttribute('date')
                    ?? $document->getAttribute('created_at'))?->format('d.m.Y'),
                'total' => (float) $document->getAttribute('total_amount'),
                'currency' => $document->getAttribute('currency_code'),
                // У заказа статус — enum с подписью, у реализации строка с аксессором.
                'status_label' => $isOrder
                    ? $document->getAttribute('status')->label()
                    : $document->getAttribute('status_label'),
                'url' => $isOrder
                    ? route('crm.orders.show', $document->getKey())
                    : route('crm.shipments.show', $document->getKey()),
            ])
            ->all();
    }
}
