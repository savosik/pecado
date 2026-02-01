<?php

namespace App\Http\Controllers\Admin;

use App\Models\SizeChart;
use App\Models\Brand;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;

class SizeChartController extends AdminController
{
    /**
     * Display a listing of the size charts.
     */
    public function index(Request $request): Response
    {
        $query = SizeChart::query()->with(['brands']);

        // Поиск
        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');
        
        $allowedSortFields = ['id', 'name', 'created_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Пагинация
        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100);

        $sizeCharts = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/Pages/SizeCharts/Index', [
            'sizeCharts' => $sizeCharts,
            'filters' => [
                'search' => $search,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
            ],
        ]);
    }

    /**
     * Show the form for creating a new size chart.
     */
    public function create(): Response
    {
        return Inertia::render('Admin/Pages/SizeCharts/Create', [
            'brands' => Brand::all(['id', 'name']),
        ]);
    }

    /**
     * Store a newly created size chart in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'uuid' => 'nullable|string|max:255',
            'name' => 'required|string|max:255',
            'values' => 'required|array',
            'values.*.size' => 'required|string',
            'brand_ids' => 'nullable|array',
            'brand_ids.*' => 'exists:brands,id',
        ]);

        $sizeChart = SizeChart::create([
            'uuid' => $request->uuid,
            'name' => $request->name,
            'values' => $request->input('values'),
        ]);

        if (!empty($request->brand_ids)) {
            $sizeChart->brands()->sync($request->brand_ids);
        }

        return redirect()
            ->route('admin.size-charts.index')
            ->with('success', 'Размерная сетка успешно создана');
    }

    /**
     * Show the form for editing the specified size chart.
     */
    public function edit(SizeChart $sizeChart): Response
    {
        $sizeChart->load('brands');

        return Inertia::render('Admin/Pages/SizeCharts/Edit', [
            'sizeChart' => [
                'id' => $sizeChart->id,
                'uuid' => $sizeChart->uuid,
                'name' => $sizeChart->name,
                'values' => is_array($sizeChart->values) ? $sizeChart->values : json_decode($sizeChart->values ?? '[]', true),
                'brand_ids' => $sizeChart->brands->pluck('id'),
            ],
            'brands' => Brand::all(['id', 'name']),
        ]);
    }

    /**
     * Update the specified size chart in storage.
     */
    public function update(Request $request, SizeChart $sizeChart): RedirectResponse
    {
        $request->validate([
            'uuid' => 'nullable|string|max:255',
            'name' => 'required|string|max:255',
            'values' => 'required|array',
            'values.*.size' => 'required|string',
            'brand_ids' => 'nullable|array',
            'brand_ids.*' => 'exists:brands,id',
        ]);

        $sizeChart->update([
            'uuid' => $request->uuid,
            'name' => $request->name,
            'values' => $request->input('values'),
        ]);

        $sizeChart->brands()->sync($request->brand_ids ?? []);

        return redirect()
            ->route('admin.size-charts.index')
            ->with('success', 'Размерная сетка успешно обновлена');
    }

    /**
     * Remove the specified size chart from storage.
     */
    public function destroy(SizeChart $sizeChart): RedirectResponse
    {
        $sizeChart->delete();

        return redirect()
            ->route('admin.size-charts.index')
            ->with('success', 'Размерная сетка успешно удалена');
    }
}
