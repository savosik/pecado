<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;
use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Region;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RegionController extends Controller
{
    use RedirectsAfterSave;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Region::query()->with(['primaryWarehouses', 'preorderWarehouses', 'currency']);

        if ($request->has('search')) {
            $query->where('name', 'like', '%'.$request->search.'%');
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
            'currencies' => Currency::select('id', 'code', 'name', 'symbol')->orderBy('code')->get(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'currency_id' => 'nullable|exists:currencies,id',
            'primary_warehouse_ids' => 'nullable|array',
            'primary_warehouse_ids.*' => 'exists:warehouses,id',
            'preorder_warehouse_ids' => 'nullable|array',
            'preorder_warehouse_ids.*' => 'exists:warehouses,id',
        ]);

        $region = Region::create([
            'name' => $validated['name'],
            'currency_id' => $validated['currency_id'] ?? null,
        ]);

        $this->syncWarehouses($region, $request->input('primary_warehouse_ids', []), 'primary');
        $this->syncWarehouses($region, $request->input('preorder_warehouse_ids', []), 'preorder');

        return $this->redirectAfterSave($request, 'admin.regions.index', 'admin.regions.edit', $region, 'Регион успешно создан');
    }

    /**
     * Display the specified region.
     */
    public function show(Region $region): \Inertia\Response
    {
        $region->load(['primaryWarehouses', 'preorderWarehouses', 'currency']);

        return Inertia::render('Admin/Pages/Regions/Show', [
            'region' => [
                'id' => $region->id,
                'name' => $region->name,
                'currency' => $region->currency ? [
                    'id' => $region->currency->id,
                    'code' => $region->currency->code,
                    'name' => $region->currency->name,
                    'symbol' => $region->currency->symbol,
                ] : null,
                'primary_warehouses' => $region->primaryWarehouses->map(fn ($w) => ['id' => $w->id, 'name' => $w->name])->toArray(),
                'preorder_warehouses' => $region->preorderWarehouses->map(fn ($w) => ['id' => $w->id, 'name' => $w->name])->toArray(),
                'created_at' => $region->created_at?->format('d.m.Y H:i'),
                'updated_at' => $region->updated_at?->format('d.m.Y H:i'),
            ],
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Region $region)
    {
        $region->load(['primaryWarehouses', 'preorderWarehouses', 'currency']);

        return Inertia::render('Admin/Pages/Regions/Edit', [
            'region' => $region,
            'warehouses' => Warehouse::all(),
            'currencies' => Currency::select('id', 'code', 'name', 'symbol')->orderBy('code')->get(),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Region $region)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'currency_id' => 'nullable|exists:currencies,id',
            'primary_warehouse_ids' => 'nullable|array',
            'primary_warehouse_ids.*' => 'exists:warehouses,id',
            'preorder_warehouse_ids' => 'nullable|array',
            'preorder_warehouse_ids.*' => 'exists:warehouses,id',
        ]);

        $region->update([
            'name' => $validated['name'],
            'currency_id' => $validated['currency_id'] ?? null,
        ]);

        $this->syncWarehouses($region, $request->input('primary_warehouse_ids', []), 'primary');
        $this->syncWarehouses($region, $request->input('preorder_warehouse_ids', []), 'preorder');

        return $this->redirectAfterSave($request, 'admin.regions.index', 'admin.regions.edit', $region, 'Регион успешно обновлен');
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
        if (! empty($warehouseIds)) {
            $region->belongsToMany(Warehouse::class, 'region_warehouse')
                ->attach($warehouseIds, ['type' => $type]);
        }
    }
}
