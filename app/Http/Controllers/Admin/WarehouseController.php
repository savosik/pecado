<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;
use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Inertia\Inertia;

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
            $query->where('name', 'like', '%'.$request->search.'%')
                ->orWhere('external_id', 'like', '%'.$request->search.'%');
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
        $validated = $request->validate($this->rules($request));

        $warehouse = Warehouse::create($validated);

        return $this->redirectAfterSave($request, 'admin.warehouses.index', 'admin.warehouses.edit', $warehouse, 'Склад успешно создан');
    }

    /**
     * Display the specified warehouse.
     */
    public function show(Warehouse $warehouse): \Inertia\Response
    {
        return Inertia::render('Admin/Pages/Warehouses/Show', [
            'warehouse' => [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'external_id' => $warehouse->external_id,
                'is_defect' => $warehouse->is_defect,
                'is_promo_sample' => $warehouse->is_promo_sample,
                'created_at' => $warehouse->created_at?->format('d.m.Y H:i'),
                'updated_at' => $warehouse->updated_at?->format('d.m.Y H:i'),
            ],
        ]);
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
        $validated = $request->validate($this->rules($request, $warehouse));

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

    /**
     * Правила валидации склада.
     *
     * Взаимоисключающесть типов: склад не может быть одновременно складом
     * некондиции и складом рекламных образцов — у обоих свой тип заказа
     * (`defect` / `promo_sample`), и `PublishOrderToErp` выбрал бы один и тот же
     * склад для двух разных потоков отгрузки.
     *
     * @return array<string, mixed>
     */
    private function rules(Request $request, ?Warehouse $warehouse = null): array
    {
        $unique = 'unique:warehouses,external_id'.($warehouse ? ','.$warehouse->id : '');

        return [
            'name' => 'required|string|max:255',
            'external_id' => 'nullable|string|max:255|'.$unique,
            'is_defect' => 'boolean',
            'is_promo_sample' => [
                'boolean',
                // Замыкание, а не declined_if: правило implicit и сработало бы
                // на отсутствующем в запросе поле
                function (string $attribute, mixed $value, \Closure $fail) use ($request) {
                    if ($value && $request->boolean('is_defect')) {
                        $fail('Склад не может быть одновременно складом некондиции и складом рекламных образцов.');
                    }
                },
            ],
        ];
    }

    /**
     * Search warehouses by name (JSON).
     */
    public function search(Request $request)
    {
        $query = $request->input('query');

        $warehouses = Warehouse::query()
            ->when($query, function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%");
            })
            ->take(20)
            ->get()
            ->map(function ($warehouse) {
                return [
                    'id' => $warehouse->id,
                    'name' => $warehouse->name,
                ];
            });

        return response()->json($warehouses);
    }
}
