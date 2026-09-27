<?php

namespace App\Http\Controllers\User;

use App\Enums\Country;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Company\CompanyClaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class CompanyController extends Controller
{
    public function index()
    {
        $companies = Company::withCount('bankAccounts')
            ->with(['contractorBalance'])
            ->where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('User/Cabinet/Companies/Index', [
            'companies' => $companies,
        ]);
    }

    public function create()
    {
        return Inertia::render('User/Cabinet/Companies/Form', [
            'company' => null,
            'countries' => collect(Country::cases())->map(fn ($c) => [
                'value' => $c->value,
                'label' => $c->label(),
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateCompany($request);
        $validated['user_id'] = Auth::id();

        $company = $this->claimOrCreateCompany($validated);

        return redirect()->route('cabinet.companies.edit', $company)
            ->with('success', 'Компания успешно создана.');
    }

    public function edit(Company $company)
    {
        $this->authorizeCompany($company);
        $company->load('bankAccounts');

        return Inertia::render('User/Cabinet/Companies/Form', [
            'company' => $company,
            'countries' => collect(Country::cases())->map(fn ($c) => [
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

    /**
     * JSON API для создания компании (например, из диалога на странице Checkout).
     * POST /cabinet/companies/api
     */
    public function apiStore(Request $request): JsonResponse
    {
        $service = app(CompanyClaimService::class);
        $validated = $request->validate($service->rules($request->input('country'), legalNameRequired: true), $service->messages());

        $validated['user_id'] = Auth::id();

        $company = $this->claimOrCreateCompany($validated);

        return response()->json([
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'legal_name' => $company->legal_name,
                'tax_id' => $company->tax_id,
                'is_default' => (bool) $company->is_default,
            ],
        ], 201);
    }

    public function toggleDefault(Company $company): JsonResponse
    {
        $this->authorizeCompany($company);

        $newValue = ! $company->is_default;

        app(CompanyClaimService::class)->setDefault(Auth::user(), $company, $newValue);

        return response()->json([
            'is_default' => $newValue,
            'company_id' => $company->id,
        ]);
    }

    private function authorizeCompany(Company $company): void
    {
        abort_if($company->user_id !== Auth::id(), 403, 'Доступ запрещён.');
    }

    private function validateCompany(Request $request, ?int $companyId = null): array
    {
        $service = app(CompanyClaimService::class);

        return $request->validate($service->rules($request->input('country'), $companyId), $service->messages());
    }

    private function claimOrCreateCompany(array $validated): Company
    {
        unset($validated['user_id']);

        return app(CompanyClaimService::class)->claimOrCreate(Auth::user(), $validated);
    }
}
