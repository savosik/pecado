<?php

namespace App\Http\Controllers\User;

use App\Enums\Country;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class CompanyController extends Controller
{
    public function index()
    {
        $companies = Company::withCount('bankAccounts')
            ->with(['contractorBalance.overdueDetails'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return Inertia::render('User/Cabinet/Companies/Index', [
            'companies' => $companies,
        ]);
    }

    public function create()
    {
        return Inertia::render('User/Cabinet/Companies/Form', [
            'company' => null,
            'countries' => collect(Country::cases())->map(fn($c) => [
                'value' => $c->value,
                'label' => $c->label(),
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateCompany($request);
        $validated['user_id'] = Auth::id();

        $company = Company::create($validated);

        return redirect()->route('cabinet.companies.edit', $company)
            ->with('success', 'Компания успешно создана.');
    }

    public function edit(Company $company)
    {
        $this->authorizeCompany($company);
        $company->load('bankAccounts');

        return Inertia::render('User/Cabinet/Companies/Form', [
            'company' => $company,
            'countries' => collect(Country::cases())->map(fn($c) => [
                'value' => $c->value,
                'label' => $c->label(),
            ]),
        ]);
    }

    public function update(Request $request, Company $company)
    {
        $this->authorizeCompany($company);

        $validated = $this->validateCompany($request, $company->id);
        $company->update($validated);

        return back()->with('success', 'Компания успешно обновлена.');
    }

    public function destroy(Request $request, Company $company)
    {
        $this->authorizeCompany($company);
        $company->delete();

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect()->route('cabinet.companies.index')
            ->with('success', 'Компания успешно удалена.');
    }

    private function authorizeCompany(Company $company): void
    {
        abort_if($company->user_id !== Auth::id(), 403, 'Доступ запрещён.');
    }

    private function validateCompany(Request $request, ?int $companyId = null): array
    {
        return $request->validate([
            'country'             => ['required', 'string'],
            'name'                => ['required', 'string', 'max:255'],
            'legal_name'          => ['nullable', 'string', 'max:255'],
            'tax_id'              => ['nullable', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:255'],
            'tax_code'            => ['nullable', 'string', 'max:255'],
            'okpo_code'           => ['nullable', 'string', 'max:255'],
            'legal_address'       => ['nullable', 'string'],
            'actual_address'      => ['nullable', 'string'],
            'phone'               => ['nullable', 'string', 'max:20', 'regex:/^\+[1-9]\d{6,14}$/'],
            'email'               => ['nullable', 'email', 'max:255'],
        ], [
            'country.required'  => 'Выберите страну.',
            'name.required'     => 'Название обязательно.',
            'name.max'          => 'Название не должно превышать 255 символов.',
            'phone.regex'       => 'Введите корректный номер телефона.',
            'email.email'       => 'Введите корректный email.',
        ]);
    }
}
