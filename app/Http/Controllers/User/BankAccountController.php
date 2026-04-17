<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyBankAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BankAccountController extends Controller
{
    public function store(Request $request)
    {
        $validated = $this->validateBankAccount($request);
        $this->authorizeCompany($validated['company_id']);

        if (! empty($validated['is_primary'])) {
            CompanyBankAccount::where('company_id', $validated['company_id'])
                ->update(['is_primary' => false]);
        }

        $account = CompanyBankAccount::create($validated);

        return response()->json($account, 201);
    }

    public function update(Request $request, CompanyBankAccount $bankAccount)
    {
        $this->authorizeCompany($bankAccount->company_id);

        $validated = $this->validateBankAccount($request);

        if (! empty($validated['is_primary'])) {
            CompanyBankAccount::where('company_id', $bankAccount->company_id)
                ->where('id', '!=', $bankAccount->id)
                ->update(['is_primary' => false]);
        }

        $bankAccount->update($validated);

        return response()->json($bankAccount);
    }

    public function destroy(CompanyBankAccount $bankAccount)
    {
        $this->authorizeCompany($bankAccount->company_id);
        $bankAccount->delete();

        return response()->json(['message' => 'Удалено']);
    }

    private function authorizeCompany(int $companyId): void
    {
        $company = Company::find($companyId);
        abort_if(! $company || $company->user_id !== Auth::id(), 403, 'Доступ запрещён.');
    }

    private function validateBankAccount(Request $request): array
    {
        return $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'bank_name' => ['required', 'string', 'max:255'],
            'bank_bik' => ['nullable', 'string', 'max:20'],
            'correspondent_account' => ['nullable', 'string', 'max:30'],
            'account_number' => ['required', 'string', 'max:30'],
            'is_primary' => ['boolean'],
        ], [
            'company_id.required' => 'Компания обязательна.',
            'company_id.exists' => 'Компания не найдена.',
            'bank_name.required' => 'Название банка обязательно.',
            'account_number.required' => 'Расчётный счёт обязателен.',
        ]);
    }
}
