<?php

namespace App\Http\Controllers\Admin;

use App\Models\ProductModel;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;

class ProductModelController extends AdminController
{
    use RedirectsAfterSave;

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
            'products' => 'nullable|array',
            'products.*' => 'exists:products,id',
        ]);

        $productModel = ProductModel::create(\Illuminate\Support\Arr::except($validated, ['products']));

        // Assign products to this model
        if (!empty($validated['products'])) {
            \App\Models\Product::whereIn('id', $validated['products'])->update(['model_id' => $productModel->id]);
        }

        return $this->redirectAfterSave($request, 'admin.product-models.index', 'admin.product-models.edit', $productModel, 'Модель товара успешно создана');
    }

    /**
     * Show the form for editing the specified product model.
     */
    public function edit(ProductModel $productModel): Response
    {
        $productModel->load(['products.media']);

        $products = $productModel->products->map(function ($product) {
            return [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'image_url' => $product->getFirstMediaUrl('main'),
                'price' => $product->base_price,
            ];
        });

        return Inertia::render('Admin/Pages/ProductModels/Edit', [
            'productModel' => array_merge($productModel->toArray(), [
                 'products' => $products
            ]),

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
            'products' => 'nullable|array',
            'products.*' => 'exists:products,id',
        ]);

        $productModel->update(\Illuminate\Support\Arr::except($validated, ['products']));

        // Sync products (One-to-Many)
        if (isset($validated['products'])) {
            // Detach products that are no longer in the list
            $productModel->products()->whereNotIn('id', $validated['products'])->update(['model_id' => null]);
            
            // Attach selected products
            if (!empty($validated['products'])) {
                \App\Models\Product::whereIn('id', $validated['products'])->update(['model_id' => $productModel->id]);
            }
        } else {
             // If products field is present (and null/empty implied if not caught above, but here we cover case if isset but maybe explicit null sent differently? 
             // Actually, if it's not in validated, we skip. If it is in validated and empty, we detach all.
             $productModel->products()->update(['model_id' => null]);
        }

        return $this->redirectAfterSave($request, 'admin.product-models.index', 'admin.product-models.edit', $productModel, 'Модель товара успешно обновлена');
    }

    /**
     * Remove the specified product model from storage.
     */
    public function destroy(ProductModel $productModel): RedirectResponse
    {
        $productModel->delete();

        return redirect()->route('admin.product-models.index')->with('success', 'Модель товара успешно удалена');
    }

    /**
     * Search product models by name (JSON).
     */
    public function search(Request $request)
    {
        $query = $request->input('query');

        $models = ProductModel::query()
            ->when($query, function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%");
            })
            ->take(20)
            ->get()
            ->map(function ($model) {
                return [
                    'id' => $model->id,
                    'name' => $model->name,
                ];
            });

        return response()->json($models);
    }
}
