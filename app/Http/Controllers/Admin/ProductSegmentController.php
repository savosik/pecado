<?php

namespace App\Http\Controllers\Admin;

use App\Models\Product;
use App\Models\ProductSegment;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;

class ProductSegmentController extends AdminController
{
    use RedirectsAfterSave;

    /**
     * Display a listing of product segments.
     */
    public function index(Request $request): Response
    {
        $query = ProductSegment::query()
            ->withCount('products');

        // Поиск
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('uuid', 'like', "%{$search}%");
            });
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');

        $allowedSortFields = ['id', 'name', 'uuid', 'created_at', 'updated_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Пагинация
        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100);

        $segments = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/Pages/ProductSegments/Index', [
            'segments' => $segments,
            'filters' => [
                'search' => $search,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
            ],
        ]);
    }

    /**
     * Show the form for creating a new product segment.
     */
    public function create(): Response
    {
        return Inertia::render('Admin/Pages/ProductSegments/Create');
    }

    /**
     * Store a newly created product segment in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'uuid' => 'nullable|uuid|unique:product_segments,uuid',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'exists:products,id',
        ], [
            'name.required' => 'Название обязательно для заполнения.',
            'name.max' => 'Название не должно превышать 255 символов.',
            'uuid.uuid' => 'Поле UUID должно быть в формате UUID.',
            'uuid.unique' => 'Сегмент с таким UUID уже существует.',
            'product_ids.array' => 'Товары должны быть массивом.',
            'product_ids.*.exists' => 'Один из выбранных товаров не найден.',
        ]);

        DB::beginTransaction();
        try {
            $segment = ProductSegment::create([
                'name' => $validated['name'],
                'uuid' => $validated['uuid'] ?? null,
            ]);

            if (!empty($validated['product_ids'])) {
                $segment->products()->sync($validated['product_ids']);
            }

            DB::commit();

            return $this->redirectAfterSave($request, 'admin.product-segments.index', 'admin.product-segments.edit', $segment, 'Сегмент успешно создан');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()
                ->back()
                ->withInput()
                ->withErrors(['error' => 'Ошибка при создании сегмента: ' . $e->getMessage()]);
        }
    }

    /**
     * Show the form for editing the specified product segment.
     */
    public function edit(ProductSegment $productSegment): Response
    {
        $productSegment->load(['products.media', 'products.brand']);

        return Inertia::render('Admin/Pages/ProductSegments/Edit', [
            'segment' => [
                'id' => $productSegment->id,
                'name' => $productSegment->name,
                'uuid' => $productSegment->uuid,
                'created_at' => $productSegment->created_at?->format('d.m.Y H:i'),
                'updated_at' => $productSegment->updated_at?->format('d.m.Y H:i'),
                'products' => $productSegment->products->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'sku' => $p->sku,
                    'brand_name' => $p->brand?->name,
                    'image_url' => $p->getFirstMediaUrl('main'),
                ]),
            ],
        ]);
    }

    /**
     * Update the specified product segment in storage.
     */
    public function update(Request $request, ProductSegment $productSegment): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'uuid' => 'nullable|uuid|unique:product_segments,uuid,' . $productSegment->id,
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'exists:products,id',
        ], [
            'name.required' => 'Название обязательно для заполнения.',
            'name.max' => 'Название не должно превышать 255 символов.',
            'uuid.uuid' => 'Поле UUID должно быть в формате UUID.',
            'uuid.unique' => 'Сегмент с таким UUID уже существует.',
            'product_ids.array' => 'Товары должны быть массивом.',
            'product_ids.*.exists' => 'Один из выбранных товаров не найден.',
        ]);

        DB::beginTransaction();
        try {
            $productSegment->update([
                'name' => $validated['name'],
                'uuid' => $validated['uuid'] ?? null,
            ]);

            // Синхронизация товаров
            $productSegment->products()->sync($validated['product_ids'] ?? []);

            DB::commit();

            return $this->redirectAfterSave($request, 'admin.product-segments.index', 'admin.product-segments.edit', $productSegment, 'Сегмент успешно обновлён');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()
                ->back()
                ->withInput()
                ->withErrors(['error' => 'Ошибка при обновлении сегмента: ' . $e->getMessage()]);
        }
    }

    /**
     * Remove the specified product segment from storage.
     */
    public function destroy(ProductSegment $productSegment): RedirectResponse
    {
        try {
            $productSegment->delete();

            return redirect()->route('admin.product-segments.index')->with('success', 'Сегмент успешно удалён');
        } catch (\Exception $e) {
            return redirect()
                ->back()
                ->withErrors(['error' => 'Ошибка при удалении сегмента: ' . $e->getMessage()]);
        }
    }

    /**
     * Search products for async selector.
     */
    public function searchProducts(Request $request): \Illuminate\Http\JsonResponse
    {
        $query = $request->input('query', '');

        $products = Product::query()
            ->when($query, function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('sku', 'like', "%{$query}%")
                  ->orWhere('code', 'like', "%{$query}%");
            })
            ->with(['brand', 'media'])
            ->select('id', 'name', 'sku', 'brand_id')
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'brand_name' => $product->brand?->name,
                    'image_url' => $product->getFirstMediaUrl('main'),
                    'label' => $product->name . ($product->sku ? " ({$product->sku})" : ''),
                ];
            });

        return response()->json($products);
    }
}
