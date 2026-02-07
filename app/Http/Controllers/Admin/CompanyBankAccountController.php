<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompanyBankAccount;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CompanyBankAccountController extends Controller
{
    public function index(Request $request)
    {
        $query = CompanyBankAccount::query()
            ->with(['company.user']);

        // Поиск
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('bank_name', 'like', "%{$search}%")
                    ->orWhere('account_number', 'like', "%{$search}%")
                    ->orWhere('bank_bik', 'like', "%{$search}%");
            });
        }

        // Фильтры
        if ($request->filled('company_id')) {
            $query->where('company_id', $request->input('company_id'));
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $bankAccounts = $query->paginate($request->input('per_page', 15));

        return Inertia::render('Admin/Pages/CompanyBankAccounts/Index', [
            'bankAccounts' => $bankAccounts,
            'filters' => $request->only(['search', 'company_id', 'sort_by', 'sort_order', 'per_page']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Pages/CompanyBankAccounts/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'bank_name' => 'required|string|max:255',
            'bank_bik' => 'nullable|string|max:255',
            'correspondent_account' => 'nullable|string|max:255',
            'account_number' => 'required|string|max:255',
            'is_primary' => 'boolean',
        ]);

        // Если это primary счет, сбросить флаг у других счетов этой компании
        if ($validated['is_primary'] ?? false) {
            CompanyBankAccount::where('company_id', $validated['company_id'])
                ->update(['is_primary' => false]);
        }

        $bankAccount = CompanyBankAccount::create($validated);

        return redirect()
            ->route('admin.company-bank-accounts.index')
            ->with('success', 'Банковский счет успешно создан');
    }

    public function edit(CompanyBankAccount $companyBankAccount)
    {
        $companyBankAccount->load(['company.user']);

        return Inertia::render('Admin/Pages/CompanyBankAccounts/Edit', [
            'bankAccount' => $companyBankAccount,
        ]);
    }

    public function update(Request $request, CompanyBankAccount $companyBankAccount)
    {
        $validated = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'bank_name' => 'required|string|max:255',
            'bank_bik' => 'nullable|string|max:255',
            'correspondent_account' => 'nullable|string|max:255',
            'account_number' => 'required|string|max:255',
            'is_primary' => 'boolean',
        ]);

        // Если это primary счет, сбросить флаг у других счетов этой компании
        if ($validated['is_primary'] ?? false) {
            CompanyBankAccount::where('company_id', $validated['company_id'])
                ->where('id', '!=', $companyBankAccount->id)
                ->update(['is_primary' => false]);
        }

        $companyBankAccount->update($validated);

        return redirect()
            ->route('admin.company-bank-accounts.index')
            ->with('success', 'Банковский счет успешно обновлен');
    }

    public function destroy(CompanyBankAccount $companyBankAccount)
    {
        $companyBankAccount->delete();

        return redirect()
            ->route('admin.company-bank-accounts.index')
            ->with('success', 'Банковский счет успешно удален');
    }

    /**
     * Async search endpoint для EntitySelector
     */
    public function search(Request $request)
    {
        $query = CompanyBankAccount::query()->with(['company.user']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('bank_name', 'like', "%{$search}%")
                    ->orWhere('account_number', 'like', "%{$search}%");
            });
        }

        $bankAccounts = $query->limit(20)
            ->get()
            ->map(function ($account) {
                return [
                    'id' => $account->id,
                    'name' => $account->bank_name . ' (' . $account->account_number . ')',
                    'bank_name' => $account->bank_name,
                    'account_number' => $account->account_number,
                    'company_name' => $account->company?->name,
                ];
            });

        return response()->json($bankAccounts);
    }
}
