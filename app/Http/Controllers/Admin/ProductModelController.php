<?php

namespace App\Http\Controllers\Admin;

use App\Models\ProductModel;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;

class ProductModelController extends AdminController
{
    /**
     * Display a listing of the product models.
     */
    public function index(Request $request): Response
    {
        $query = ProductModel::query();

        // Поиск
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }
        


        // Сортировка
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');
        
        $allowedSortFields = ['id', 'name', 'created_at', 'code'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Пагинация
        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100);

        $productModels = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/Pages/ProductModels/Index', [
            'productModels' => $productModels,
            'filters' => [
                'search' => $search,

                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
            ],

        ]);
    }

    /**
     * Show the form for creating a new product model.
     */
    public function create(): Response
    {
        return Inertia::render('Admin/Pages/ProductModels/Create', [

        ]);
    }

    /**
     * Store a newly created product model in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',

            'code' => 'nullable|string|max:255',
            'external_id' => 'nullable|string|max:255',
        ]);

        ProductModel::create($validated);

        return redirect()
            ->route('admin.product-models.index')
            ->with('success', 'Модель товара успешно создана');
    }

    /**
     * Show the form for editing the specified product model.
     */
    public function edit(ProductModel $productModel): Response
    {
        return Inertia::render('Admin/Pages/ProductModels/Edit', [
            'productModel' => $productModel,

        ]);
    }

    /**
     * Update the specified product model in storage.
     */
    public function update(Request $request, ProductModel $productModel): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',

            'code' => 'nullable|string|max:255',
            'external_id' => 'nullable|string|max:255',
        ]);

        $productModel->update($validated);

        return redirect()
            ->route('admin.product-models.index')
            ->with('success', 'Модель товара успешно обновлена');
    }

    /**
     * Remove the specified product model from storage.
     */
    public function destroy(ProductModel $productModel): RedirectResponse
    {
        $productModel->delete();

        return redirect()
            ->route('admin.product-models.index')
            ->with('success', 'Модель товара успешно удалена');
    }
}
