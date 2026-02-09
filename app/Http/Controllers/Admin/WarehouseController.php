<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;

class WarehouseController extends Controller
{
    use RedirectsAfterSave;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Warehouse::query();

        if ($request->has('search')) {
            $query->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('external_id', 'like', '%' . $request->search . '%');
        }

        if ($request->has('sort_by') && $request->has('sort_order')) {
            $query->orderBy($request->sort_by, $request->sort_order);
        } else {
            $query->orderBy('id', 'desc');
        }

        $warehouses = $query->paginate($request->per_page ?? 10)
            ->withQueryString();

        return Inertia::render('Admin/Pages/Warehouses/Index', [
            'warehouses' => $warehouses,
            'filters' => $request->only(['search', 'sort_by', 'sort_order']),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Admin/Pages/Warehouses/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'external_id' => 'nullable|string|max:255|unique:warehouses,external_id',
        ]);

        $warehouse = Warehouse::create($validated);

        return $this->redirectAfterSave($request, 'admin.warehouses.index', 'admin.warehouses.edit', $warehouse, 'Склад успешно создан');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Warehouse $warehouse)
    {
        return Inertia::render('Admin/Pages/Warehouses/Edit', [
            'warehouse' => $warehouse,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Warehouse $warehouse)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'external_id' => 'nullable|string|max:255|unique:warehouses,external_id,' . $warehouse->id,
        ]);

        $warehouse->update($validated);

        return $this->redirectAfterSave($request, 'admin.warehouses.index', 'admin.warehouses.edit', $warehouse, 'Склад успешно обновлен');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Warehouse $warehouse)
    {
        $warehouse->delete();

        return redirect()->route('admin.warehouses.index')
            ->with('success', 'Склад успешно удален');
    }
}
