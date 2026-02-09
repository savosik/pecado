<?php

namespace App\Http\Controllers\Admin;

use App\Models\ProductSelection;
use App\Models\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;

class ProductSelectionController extends AdminController
{
    use RedirectsAfterSave;

    /**
     * Display a listing of product selections.
     */
    public function index(Request $request): Response
    {
        $query = ProductSelection::query()->withCount('products');

        // Поиск
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');
        
        $allowedSortFields = ['id', 'name', 'created_at', 'updated_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Пагинация
        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100);

        $productSelections = $query->paginate($perPage)->withQueryString();

        $productSelections->through(function ($selection) {
            $selection->desktop_image_url = $selection->getFirstMediaUrl('desktop');
            return $selection;
        });

        return Inertia::render('Admin/Pages/ProductSelections/Index', [
            'product_selections' => $productSelections,
            'filters' => [
                'search' => $search,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
            ],
        ]);
    }

    /**
     * Show the form for creating a new product selection.
     */
    public function create(): Response
    {
        return Inertia::render('Admin/Pages/ProductSelections/Create');
    }

    /**
     * Store a newly created product selection in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'short_description' => 'nullable|string',
            'description' => 'nullable|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'exists:products,id',
            'desktop_image' => 'nullable|image|max:10240',
            'mobile_image' => 'nullable|image|max:10240',
        ]);

        DB::beginTransaction();
        try {
            $productSelection = ProductSelection::create([
                'name' => $validated['name'],
                'short_description' => $validated['short_description'] ?? null,
                'description' => $validated['description'] ?? null,
                'meta_title' => $validated['meta_title'] ?? null,
                'meta_description' => $validated['meta_description'] ?? null,
            ]);

            // Привязка товаров
            if (!empty($validated['product_ids'])) {
                $productSelection->products()->sync($validated['product_ids']);
            }

            // Загрузка медиафайлов
            if ($request->hasFile('desktop_image')) {
                $productSelection->addMedia($request->file('desktop_image'))
                    ->toMediaCollection('desktop');
            }

            if ($request->hasFile('mobile_image')) {
                $productSelection->addMedia($request->file('mobile_image'))
                    ->toMediaCollection('mobile');
            }

            DB::commit();

            return $this->redirectAfterSave($request, 'admin.product-selections.index', 'admin.product-selections.edit', $productSelection, 'Подборка успешно создана');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()
                ->back()
                ->withInput()
                ->withErrors(['error' => 'Ошибка при создании подборки: ' . $e->getMessage()]);
        }
    }

    /**
     * Display the specified product selection.
     */
    public function show(ProductSelection $productSelection): Response
    {
        $productSelection->load(['products.brand', 'products.media']);

        return Inertia::render('Admin/Pages/ProductSelections/Show', [
            'product_selection' => [
                'id' => $productSelection->id,
                'name' => $productSelection->name,
                'short_description' => $productSelection->short_description,
                'description' => $productSelection->description,
                'meta_title' => $productSelection->meta_title,
                'meta_description' => $productSelection->meta_description,
                'created_at' => $productSelection->created_at?->format('d.m.Y H:i'),
                'updated_at' => $productSelection->updated_at?->format('d.m.Y H:i'),
                'desktop_image_url' => $productSelection->getFirstMediaUrl('desktop'),
                'mobile_image_url' => $productSelection->getFirstMediaUrl('mobile'),
                'products' => $productSelection->products->map(function ($product) {
                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'brand_name' => $product->brand?->name,
                        'base_price' => $product->base_price,
                        'image_url' => $product->getFirstMediaUrl('main'),
                    ];
                }),
            ],
        ]);
    }

    /**
     * Show the form for editing the specified product selection.
     */
    public function edit(ProductSelection $productSelection): Response
    {
        $productSelection->load(['products', 'media']);

        return Inertia::render('Admin/Pages/ProductSelections/Edit', [
            'product_selection' => [
                'id' => $productSelection->id,
                'name' => $productSelection->name,
                'short_description' => $productSelection->short_description,
                'description' => $productSelection->description,
                'meta_title' => $productSelection->meta_title,
                'meta_description' => $productSelection->meta_description,
                'product_ids' => $productSelection->products->pluck('id'),
                'desktop_image_url' => $productSelection->getFirstMediaUrl('desktop'),
                'desktop_image_id' => $productSelection->getFirstMedia('desktop')?->id,
                'mobile_image_url' => $productSelection->getFirstMediaUrl('mobile'),
                'mobile_image_id' => $productSelection->getFirstMedia('mobile')?->id,
            ],
        ]);
    }

    /**
     * Update the specified product selection in storage.
     */
    public function update(Request $request, ProductSelection $productSelection): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'short_description' => 'nullable|string',
            'description' => 'nullable|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'exists:products,id',
            'desktop_image' => 'nullable|image|max:10240',
            'mobile_image' => 'nullable|image|max:10240',
            'delete_desktop_image' => 'nullable|boolean',
            'delete_mobile_image' => 'nullable|boolean',
        ]);

        DB::beginTransaction();
        try {
            $productSelection->update([
                'name' => $validated['name'],
                'short_description' => $validated['short_description'] ?? null,
                'description' => $validated['description'] ?? null,
                'meta_title' => $validated['meta_title'] ?? null,
                'meta_description' => $validated['meta_description'] ?? null,
            ]);

            // Синхронизация товаров
            $productSelection->products()->sync($validated['product_ids'] ?? []);

            // Удаление desktop изображения
            if (!empty($validated['delete_desktop_image'])) {
                $productSelection->clearMediaCollection('desktop');
            }

            // Загрузка нового desktop изображения
            if ($request->hasFile('desktop_image')) {
                $productSelection->clearMediaCollection('desktop');
                $productSelection->addMedia($request->file('desktop_image'))
                    ->toMediaCollection('desktop');
            }

            // Удаление mobile изображения
            if (!empty($validated['delete_mobile_image'])) {
                $productSelection->clearMediaCollection('mobile');
            }

            // Загрузка нового mobile изображения
            if ($request->hasFile('mobile_image')) {
                $productSelection->clearMediaCollection('mobile');
                $productSelection->addMedia($request->file('mobile_image'))
                    ->toMediaCollection('mobile');
            }

            DB::commit();

            return $this->redirectAfterSave($request, 'admin.product-selections.index', 'admin.product-selections.edit', $selection, 'Подборка успешно обновлена');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()
                ->back()
                ->withInput()
                ->withErrors(['error' => 'Ошибка при обновлении подборки: ' . $e->getMessage()]);
        }
    }

    /**
     * Remove the specified product selection from storage.
     */
    public function destroy(ProductSelection $productSelection): RedirectResponse
    {
        try {
            $productSelection->delete();

            return redirect()->route('admin.product-selections.index')->with('success', 'Подборка успешно удалена');
        } catch (\Exception $e) {
            return redirect()
                ->back()
                ->withErrors(['error' => 'Ошибка при удалении подборки: ' . $e->getMessage()]);
        }
    }

    /**
     * Delete media from product selection.
     */
    public function deleteMedia(Request $request, ProductSelection $productSelection): RedirectResponse
    {
        $validated = $request->validate([
            'media_id' => 'required|integer|exists:media,id',
        ]);

        $media = $productSelection->media()->find($validated['media_id']);
        
        if ($media) {
            $media->delete();
            return redirect()->back()->with('success', 'Медиафайл успешно удалён');
        }

        return redirect()->back()->withErrors(['error' => 'Медиафайл не найден']);
    }
}
