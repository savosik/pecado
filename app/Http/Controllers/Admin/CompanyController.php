<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Country;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CompanyController extends Controller
{
    public function index(Request $request)
    {
        $query = Company::query()
            ->with('user')
            ->withCount('bankAccounts');

        // Поиск
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('legal_name', 'like', "%{$search}%")
                    ->orWhere('tax_id', 'like', "%{$search}%")
                    ->orWhere('registration_number', 'like', "%{$search}%");
            });
        }

        // Фильтры
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        if ($request->filled('country')) {
            $query->where('country', $request->input('country'));
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $companies = $query->paginate($request->input('per_page', 15));

        return Inertia::render('Admin/Pages/Companies/Index', [
            'companies' => $companies,
            'filters' => $request->only(['search', 'user_id', 'country', 'sort_by', 'sort_order', 'per_page']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Pages/Companies/Create', [
            'countries' => collect(Country::cases())->map(fn($country) => [
                'value' => $country->value,
                'label' => $country->value,
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'country' => 'required|string',
            'name' => 'required|string|max:255',
            'legal_name' => 'nullable|string|max:255',
            'tax_id' => 'nullable|string|max:255',
            'registration_number' => 'nullable|string|max:255',
            'tax_code' => 'nullable|string|max:255',
            'okpo_code' => 'nullable|string|max:255',
            'legal_address' => 'nullable|string',
            'actual_address' => 'nullable|string',
            'phone' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'erp_id' => 'nullable|string|max:255|unique:companies,erp_id',
        ]);

        $company = Company::create($validated);

        return redirect()
            ->route('admin.companies.index')
            ->with('success', 'Компания успешно создана');
    }

    public function edit(Company $company)
    {
        $company->load(['user', 'bankAccounts']);

        return Inertia::render('Admin/Pages/Companies/Edit', [
            'company' => $company,
            'countries' => collect(Country::cases())->map(fn($country) => [
                'value' => $country->value,
                'label' => $country->value,
            ]),
        ]);
    }

    public function update(Request $request, Company $company)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'country' => 'required|string',
            'name' => 'required|string|max:255',
            'legal_name' => 'nullable|string|max:255',
            'tax_id' => 'nullable|string|max:255',
            'registration_number' => 'nullable|string|max:255',
            'tax_code' => 'nullable|string|max:255',
            'okpo_code' => 'nullable|string|max:255',
            'legal_address' => 'nullable|string',
            'actual_address' => 'nullable|string',
            'phone' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'erp_id' => 'nullable|string|max:255|unique:companies,erp_id,' . $company->id,
        ]);

        $company->update($validated);

        return redirect()
            ->route('admin.companies.index')
            ->with('success', 'Компания успешно обновлена');
    }

    public function destroy(Company $company)
    {
        $company->delete();

        return redirect()
            ->route('admin.companies.index')
            ->with('success', 'Компания успешно удалена');
    }

    /**
     * Async search endpoint для EntitySelector
     */
    public function search(Request $request)
    {
        $query = Company::query()->with('user');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('legal_name', 'like', "%{$search}%")
                    ->orWhere('tax_id', 'like', "%{$search}%");
            });
        }

        $companies = $query->select('id', 'name', 'user_id')
            ->limit(20)
            ->get()
            ->map(function ($company) {
                return [
                    'id' => $company->id,
                    'name' => $company->name . ' (' . ($company->user ? $company->user->name : 'No user') . ')',
                    'display_name' => $company->name,
                    'user_name' => $company->user?->name,
                ];
            });

        return response()->json($companies);
    }
}
