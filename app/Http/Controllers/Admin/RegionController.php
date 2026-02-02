<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Region;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RegionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Region::query()->with(['primaryWarehouses', 'preorderWarehouses']);

        if ($request->has('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        if ($request->has('sort_by') && $request->has('sort_order')) {
            $query->orderBy($request->sort_by, $request->sort_order);
        } else {
            $query->orderBy('id', 'desc');
        }

        $regions = $query->paginate($request->per_page ?? 10)
            ->withQueryString();

        return Inertia::render('Admin/Pages/Regions/Index', [
            'regions' => $regions,
            'filters' => $request->only(['search', 'sort_by', 'sort_order']),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Admin/Pages/Regions/Create', [
            'warehouses' => Warehouse::all(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'primary_warehouse_ids' => 'nullable|array',
            'primary_warehouse_ids.*' => 'exists:warehouses,id',
            'preorder_warehouse_ids' => 'nullable|array',
            'preorder_warehouse_ids.*' => 'exists:warehouses,id',
        ]);

        $region = Region::create(['name' => $validated['name']]);

        $this->syncWarehouses($region, $request->input('primary_warehouse_ids', []), 'primary');
        $this->syncWarehouses($region, $request->input('preorder_warehouse_ids', []), 'preorder');

        return redirect()->route('admin.regions.index')
            ->with('success', 'Регион успешно создан');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Region $region)
    {
        $region->load(['primaryWarehouses', 'preorderWarehouses']);

        return Inertia::render('Admin/Pages/Regions/Edit', [
            'region' => $region,
            'warehouses' => Warehouse::all(),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Region $region)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'primary_warehouse_ids' => 'nullable|array',
            'primary_warehouse_ids.*' => 'exists:warehouses,id',
            'preorder_warehouse_ids' => 'nullable|array',
            'preorder_warehouse_ids.*' => 'exists:warehouses,id',
        ]);

        $region->update(['name' => $validated['name']]);

        $this->syncWarehouses($region, $request->input('primary_warehouse_ids', []), 'primary');
        $this->syncWarehouses($region, $request->input('preorder_warehouse_ids', []), 'preorder');

        return redirect()->route('admin.regions.index')
            ->with('success', 'Регион успешно обновлен');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Region $region)
    {
        $region->delete();

        return redirect()->route('admin.regions.index')
            ->with('success', 'Регион успешно удален');
    }

    /**
     * Sync warehouses for a region by type.
     */
    protected function syncWarehouses(Region $region, array $warehouseIds, string $type)
    {
        // First detach existing warehouses of this type
        $idsToDetach = $region->belongsToMany(Warehouse::class, 'region_warehouse')
            ->wherePivot('type', $type)
            ->pluck('warehouses.id');

        if ($idsToDetach->isNotEmpty()) {
            $region->belongsToMany(Warehouse::class, 'region_warehouse')
                ->wherePivot('type', $type)
                ->detach($idsToDetach);
        }

        // Attach new ones with the type
        if (!empty($warehouseIds)) {
             $region->belongsToMany(Warehouse::class, 'region_warehouse')
                ->attach($warehouseIds, ['type' => $type]);
        }
    }
}
