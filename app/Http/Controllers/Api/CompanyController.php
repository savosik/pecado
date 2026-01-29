<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CompanyController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Company::class);

        // CompanyScope automatically filters by user_id for non-admins
        $companies = Company::with('bankAccounts')->paginate(15);

        return response()->json($companies);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Company::class);

        $validated = $request->validate([
            'country' => 'required|in:RU,BY,KZ',
            'name' => 'required|string|max:255',
            'legal_name' => 'required|string|max:255',
            'tax_id' => 'required|string|max:50',
            'registration_number' => 'nullable|string|max:50',
            'tax_code' => 'nullable|string|max:50',
            'okpo_code' => 'nullable|string|max:50',
            'legal_address' => 'required|string',
            'actual_address' => 'nullable|string',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
        ]);

        $validated['user_id'] = $request->user()->id;

        $company = Company::create($validated);

        return response()->json([
            'message' => 'Company created successfully',
            'company' => $company,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Company $company): JsonResponse
    {
        Gate::authorize('view', $company);

        $company->load('bankAccounts');

        return response()->json($company);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Company $company): JsonResponse
    {
        Gate::authorize('update', $company);

        $validated = $request->validate([
            'country' => 'sometimes|in:RU,BY,KZ',
            'name' => 'sometimes|string|max:255',
            'legal_name' => 'sometimes|string|max:255',
            'tax_id' => 'sometimes|string|max:50',
            'registration_number' => 'nullable|string|max:50',
            'tax_code' => 'nullable|string|max:50',
            'okpo_code' => 'nullable|string|max:50',
            'legal_address' => 'sometimes|string',
            'actual_address' => 'nullable|string',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
        ]);

        $company->update($validated);

        return response()->json([
            'message' => 'Company updated successfully',
            'company' => $company,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Company $company): JsonResponse
    {
        Gate::authorize('delete', $company);

        $company->delete();

        return response()->json([
            'message' => 'Company deleted successfully',
        ]);
    }
}
