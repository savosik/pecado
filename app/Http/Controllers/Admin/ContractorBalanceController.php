<?php

namespace App\Http\Controllers\Admin;

use App\Models\ContractorBalance;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ContractorBalanceController extends Controller
{
    public function index(Request $request)
    {
        $query = ContractorBalance::query()
            ->with(['user', 'company']);

        // Поиск по пользователю / ИНН / компании
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('tax_id', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })
                    ->orWhereHas('company', function ($cq) use ($search) {
                        $cq->where('name', 'like', "%{$search}%")
                            ->orWhere('legal_name', 'like', "%{$search}%");
                    });
            });
        }

        // Фильтр по пользователю
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');
        $allowed = ['id', 'tax_id', 'current_balance', 'overdue_debt', 'balance_erp_updated_at'];
        if (in_array($sortBy, $allowed)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Пагинация
        $perPage = $request->input('per_page', 15);
        $balances = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/Pages/ContractorBalances/Index', [
            'balances' => $balances,
            'filters' => $request->only(['search', 'user_id', 'sort_by', 'sort_order', 'per_page']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Pages/ContractorBalances/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'tax_id' => [
                'required', 'string', 'max:50',
                Rule::unique('contractor_balances')->where(fn ($q) => $q->where('user_id', $request->user_id)),
            ],
            'contractor_uuid' => 'nullable|string|max:255',
            'current_balance' => 'required|numeric',
            'overdue_debt' => 'nullable|numeric|min:0',
            'balance_erp_updated_at' => 'nullable|date',
            'overdue_details' => 'nullable|array',
            'overdue_details.*.shipment_uuid' => 'required|string|max:255',
            'overdue_details.*.amount' => 'required|numeric|min:0',
            'overdue_details.*.due_date' => 'required|date',
        ], [
            'user_id.required' => 'Необходимо выбрать пользователя.',
            'user_id.exists' => 'Пользователь не найден.',
            'tax_id.required' => 'ИНН контрагента обязателен.',
            'tax_id.unique' => 'У этого пользователя уже есть баланс с таким ИНН.',
            'current_balance.required' => 'Текущий баланс обязателен.',
            'current_balance.numeric' => 'Баланс должен быть числом.',
            'overdue_debt.numeric' => 'Просроченная задолженность должна быть числом.',
            'overdue_debt.min' => 'Просроченная задолженность не может быть отрицательной.',
            'overdue_details.*.shipment_uuid.required' => 'UUID реализации обязателен.',
            'overdue_details.*.amount.required' => 'Сумма просрочки обязательна.',
            'overdue_details.*.amount.min' => 'Сумма просрочки не может быть отрицательной.',
            'overdue_details.*.due_date.required' => 'Дата оплаты обязательна.',
            'overdue_details.*.due_date.date' => 'Неверный формат даты оплаты.',
        ]);

        $balance = DB::transaction(function () use ($validated) {
            $balance = ContractorBalance::create([
                'user_id' => $validated['user_id'],
                'tax_id' => $validated['tax_id'],
                'contractor_uuid' => $validated['contractor_uuid'] ?? null,
                'current_balance' => $validated['current_balance'],
                'overdue_debt' => $validated['overdue_debt'] ?? 0,
                'balance_erp_updated_at' => $validated['balance_erp_updated_at'] ?? null,
            ]);

            foreach ($validated['overdue_details'] ?? [] as $detail) {
                $balance->overdueDetails()->create([
                    'shipment_uuid' => $detail['shipment_uuid'],
                    'shipment_id' => $this->resolveShipmentId($detail['shipment_uuid']),
                    'amount' => $detail['amount'],
                    'due_date' => $detail['due_date'],
                ]);
            }

            return $balance;
        });

        return redirect()
            ->route('admin.contractor-balances.show', $balance)
            ->with('success', 'Баланс контрагента успешно создан');
    }

    public function show(ContractorBalance $contractorBalance)
    {
        $contractorBalance->load([
            'user',
            'company',
            'overdueDetails.organization',
            // Реализация нужна ради номера и даты: без неё в таблице остаётся
            // голый UUID из 1С. NULL — документ ещё не приехал.
            'overdueDetails.shipment:id,uuid,erp_number,number,date,total_amount',
        ]);

        return Inertia::render('Admin/Pages/ContractorBalances/Show', [
            'balance' => $contractorBalance,
            // v15.8.0: разрез по нашим организациям. Пустой массив — 1С детализацию
            // ещё не присылала, блок в интерфейсе не показывается.
            'organizationBalances' => $this->organizationBalances($contractorBalance),
            'organizationsEnabled' => config('erp.organizations.enabled'),
            // Карточка реализации закрыта своим правом — без него ссылка не нужна.
            'canViewShipments' => (bool) auth()->user()?->can('shipments.view'),
        ]);
    }

    /**
     * Разрез задолженности контрагента по нашим организациям.
     *
     * Строки с нулями оставляем: обнулённая строка означает «долг погашен»,
     * и менеджеру это полезно видеть — в отличие от клиента, которому нули
     * в кабинете только мешают.
     *
     * @return array<int, array<string, mixed>>
     */
    private function organizationBalances(ContractorBalance $contractorBalance): array
    {
        if (! config('erp.organizations.enabled') || ! $contractorBalance->company_id) {
            return [];
        }

        return \App\Models\ContractorOrganizationBalance::query()
            ->where('company_id', $contractorBalance->company_id)
            ->with('organization')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'organization_name' => $row->organization?->name,
                'is_stub' => (bool) $row->organization?->is_stub,
                'current_balance' => $row->current_balance,
                'overdue_debt' => $row->overdue_debt,
                'balance_erp_updated_at' => $row->balance_erp_updated_at?->format('d.m.Y H:i'),
            ])
            ->all();
    }

    public function edit(ContractorBalance $contractorBalance)
    {
        $contractorBalance->load(['user', 'company', 'overdueDetails']);

        return Inertia::render('Admin/Pages/ContractorBalances/Edit', [
            'balance' => $contractorBalance,
        ]);
    }

    public function update(Request $request, ContractorBalance $contractorBalance)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'tax_id' => [
                'required', 'string', 'max:50',
                Rule::unique('contractor_balances')
                    ->where(fn ($q) => $q->where('user_id', $request->user_id))
                    ->ignore($contractorBalance->id),
            ],
            'contractor_uuid' => 'nullable|string|max:255',
            'current_balance' => 'required|numeric',
            'overdue_debt' => 'nullable|numeric|min:0',
            'balance_erp_updated_at' => 'nullable|date',
            'overdue_details' => 'nullable|array',
            'overdue_details.*.shipment_uuid' => 'required|string|max:255',
            'overdue_details.*.amount' => 'required|numeric|min:0',
            'overdue_details.*.due_date' => 'required|date',
        ], [
            'user_id.required' => 'Необходимо выбрать пользователя.',
            'user_id.exists' => 'Пользователь не найден.',
            'tax_id.required' => 'ИНН контрагента обязателен.',
            'tax_id.unique' => 'У этого пользователя уже есть баланс с таким ИНН.',
            'current_balance.required' => 'Текущий баланс обязателен.',
            'current_balance.numeric' => 'Баланс должен быть числом.',
            'overdue_debt.numeric' => 'Просроченная задолженность должна быть числом.',
            'overdue_debt.min' => 'Просроченная задолженность не может быть отрицательной.',
            'overdue_details.*.shipment_uuid.required' => 'UUID реализации обязателен.',
            'overdue_details.*.amount.required' => 'Сумма просрочки обязательна.',
            'overdue_details.*.amount.min' => 'Сумма просрочки не может быть отрицательной.',
            'overdue_details.*.due_date.required' => 'Дата оплаты обязательна.',
            'overdue_details.*.due_date.date' => 'Неверный формат даты оплаты.',
        ]);

        DB::transaction(function () use ($validated, $contractorBalance) {
            $contractorBalance->update([
                'user_id' => $validated['user_id'],
                'tax_id' => $validated['tax_id'],
                'contractor_uuid' => $validated['contractor_uuid'] ?? null,
                'current_balance' => $validated['current_balance'],
                'overdue_debt' => $validated['overdue_debt'] ?? 0,
                'balance_erp_updated_at' => $validated['balance_erp_updated_at'] ?? null,
            ]);

            // Полная замена overdue_details
            $contractorBalance->overdueDetails()->delete();
            foreach ($validated['overdue_details'] ?? [] as $detail) {
                $contractorBalance->overdueDetails()->create([
                    'shipment_uuid' => $detail['shipment_uuid'],
                    'shipment_id' => $this->resolveShipmentId($detail['shipment_uuid']),
                    'amount' => $detail['amount'],
                    'due_date' => $detail['due_date'],
                ]);
            }
        });

        return redirect()
            ->route('admin.contractor-balances.show', $contractorBalance)
            ->with('success', 'Баланс контрагента успешно обновлён');
    }

    /**
     * Реализация сайта по UUID из формы. NULL — документа ещё нет,
     * связь доклеится, когда 1С пришлёт реализацию.
     */
    private function resolveShipmentId(string $shipmentUuid): ?int
    {
        return \App\Models\Shipment::where('uuid', $shipmentUuid)->value('id');
    }

    public function destroy(ContractorBalance $contractorBalance)
    {
        $contractorBalance->delete();

        return redirect()
            ->route('admin.contractor-balances.index')
            ->with('success', 'Баланс контрагента успешно удалён');
    }

    public function search(Request $request)
    {
        $query = ContractorBalance::query()->with(['user', 'company']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('tax_id', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $balances = $query->limit(20)->get()->map(function ($balance) {
            return [
                'id' => $balance->id,
                'name' => $balance->user->name.' ('.$balance->tax_id.')',
                'tax_id' => $balance->tax_id,
                'current_balance' => $balance->current_balance,
            ];
        });

        return response()->json($balances);
    }
}
