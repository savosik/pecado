<?php

namespace App\Http\Controllers\Admin;

use App\Models\Product;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ProductModel;
use App\Models\SizeChart;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;

class ProductController extends AdminController
{
    /**
     * Display a listing of the products.
     */
    public function index(Request $request): Response
    {
        $query = Product::query()
            ->with(['brand', 'model', 'categories', 'media']);

        // Поиск
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');
        
        $allowedSortFields = ['id', 'name', 'base_price', 'created_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Пагинация
        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100); // Ограничение от 5 до 100

        $products = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/Pages/Products/Index', [
            'products' => $products,
            'filters' => [
                'search' => $search,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
            ],
        ]);
    }

    /**
     * Show the form for creating a new product.
     */
    public function create(): Response
    {
        return Inertia::render('Admin/Pages/Products/Create', [
            'brands' => Brand::select('id', 'name')->orderBy('name')->get(),
            'categories' => Category::select('id', 'name', 'parent_id')->orderBy('name')->get(),
            'productModels' => ProductModel::select('id', 'name')->orderBy('name')->get(),
            'sizeCharts' => SizeChart::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    /**
     * Store a newly created product in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:products,slug',
            'base_price' => 'required|numeric|min:0',
            'brand_id' => 'nullable|exists:brands,id',
            'model_id' => 'nullable|exists:product_models,id',
            'size_chart_id' => 'nullable|exists:size_charts,id',
            'description' => 'nullable|string',
            'description_html' => 'nullable|string',
            'short_description' => 'nullable|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'sku' => 'nullable|string|max:255',
            'code' => 'nullable|string|max:255',
            'external_id' => 'nullable|string|max:255',
            'url' => 'nullable|url|max:255',
            'barcode' => 'nullable|string|max:255',
            'tnved' => 'nullable|string|max:255',
            'is_new' => 'boolean',
            'is_bestseller' => 'boolean',
            'is_marked' => 'boolean',
            'is_liquidation' => 'boolean',
            'for_marketplaces' => 'boolean',
            'categories' => 'nullable|array',
            'categories.*' => 'exists:categories,id',
            'image' => 'nullable|image|max:10240',
            'additional_images' => 'nullable|array',
            'additional_images.*' => 'image|max:10240',
            'video' => 'nullable|mimes:mp4,webm,mov|max:51200',
        ]);

        // Генерация slug если не указан
        if (empty($validated['slug'])) {
            $validated['slug'] = \Illuminate\Support\Str::slug($validated['name']);
        }

        $product = Product::create($validated);

        // Синхронизация категорий
        if (isset($validated['categories'])) {
            $product->categories()->sync($validated['categories']);
        }

        // Загрузка главного изображения
        if ($request->hasFile('image')) {
            $product->addMediaFromRequest('image')
                ->toMediaCollection('main');
        }

        // Загрузка дополнительных изображений
        if ($request->hasFile('additional_images')) {
            foreach ($request->file('additional_images') as $image) {
                $product->addMedia($image)
                    ->toMediaCollection('additional');
            }
        }

        // Загрузка видео
        if ($request->hasFile('video')) {
            $product->addMediaFromRequest('video')
                ->toMediaCollection('video');
        }

        return redirect()
            ->route('admin.products.index')
            ->with('success', 'Товар успешно создан');
    }

    /**
     * Show the form for editing the specified product.
     */
    public function edit(Product $product): Response
    {
        $product->load(['brand', 'model', 'categories', 'sizeChart', 'media']);

        return Inertia::render('Admin/Pages/Products/Edit', [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'base_price' => $product->base_price,
                'brand_id' => $product->brand_id,
                'model_id' => $product->model_id,
                'size_chart_id' => $product->size_chart_id,
                'description' => $product->description,
                'description_html' => $product->description_html,
                'short_description' => $product->short_description,
                'meta_title' => $product->meta_title,
                'meta_description' => $product->meta_description,
                'sku' => $product->sku,
                'code' => $product->code,
                'external_id' => $product->external_id,
                'url' => $product->url,
                'barcode' => $product->barcode,
                'tnved' => $product->tnved,
                'is_new' => $product->is_new,
                'is_bestseller' => $product->is_bestseller,
                'is_marked' => $product->is_marked,
                'is_liquidation' => $product->is_liquidation,
                'for_marketplaces' => $product->for_marketplaces,
                'categories' => $product->categories->pluck('id')->toArray(),
                'brand' => $product->brand,
                'model' => $product->model,
                'main_image' => $product->getFirstMediaUrl('main'),
                'main_image_id' => $product->getFirstMedia('main')?->id,
                'additional_media' => $product->getMedia('additional')->map(function ($media) {
                    return [
                        'id' => $media->id,
                        'url' => $media->getUrl(),
                    ];
                }),
                'video_url' => $product->getFirstMediaUrl('video'),
                'video_id' => $product->getFirstMedia('video')?->id,
            ],
            'brands' => Brand::select('id', 'name')->orderBy('name')->get(),
            'categories' => Category::select('id', 'name', 'parent_id')->orderBy('name')->get(),
            'productModels' => ProductModel::select('id', 'name')->orderBy('name')->get(),
            'sizeCharts' => SizeChart::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    /**
     * Update the specified product in storage.
     */
    public function update(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:products,slug,' . $product->id,
            'base_price' => 'required|numeric|min:0',
            'brand_id' => 'nullable|exists:brands,id',
            'model_id' => 'nullable|exists:product_models,id',
            'size_chart_id' => 'nullable|exists:size_charts,id',
            'description' => 'nullable|string',
            'description_html' => 'nullable|string',
            'short_description' => 'nullable|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'sku' => 'nullable|string|max:255',
            'code' => 'nullable|string|max:255',
            'external_id' => 'nullable|string|max:255',
            'url' => 'nullable|url|max:255',
            'barcode' => 'nullable|string|max:255',
            'tnved' => 'nullable|string|max:255',
            'is_new' => 'boolean',
            'is_bestseller' => 'boolean',
            'is_marked' => 'boolean',
            'is_liquidation' => 'boolean',
            'for_marketplaces' => 'boolean',
            'categories' => 'nullable|array',
            'categories.*' => 'exists:categories,id',
            'image' => 'nullable|image|max:10240',
            'additional_images' => 'nullable|array',
            'additional_images.*' => 'image|max:10240',
            'video' => 'nullable|mimes:mp4,webm,mov|max:51200',
        ]);

        // Генерация slug если не указан
        if (empty($validated['slug'])) {
            $validated['slug'] = \Illuminate\Support\Str::slug($validated['name']);
        }

        $product->update($validated);

        // Синхронизация категорий
        if (isset($validated['categories'])) {
            $product->categories()->sync($validated['categories']);
        } else {
            $product->categories()->detach();
        }

        // Обновление главного изображения
        if ($request->hasFile('image')) {
            $product->clearMediaCollection('main');
            $product->addMediaFromRequest('image')
                ->toMediaCollection('main');
        }

        // Обновление дополнительных изображений
        if ($request->hasFile('additional_images')) {
            foreach ($request->file('additional_images') as $image) {
                $product->addMedia($image)
                    ->toMediaCollection('additional');
            }
        }

        // Обновление видео
        if ($request->hasFile('video')) {
            $product->clearMediaCollection('video');
            $product->addMediaFromRequest('video')
                ->toMediaCollection('video');
        }

        return redirect()
            ->route('admin.products.index')
            ->with('success', 'Товар успешно обновлён');
    }

    /**
     * Remove the specified product from storage.
     */
    public function destroy(Product $product): RedirectResponse
    {
        $product->delete();

        return redirect()
            ->route('admin.products.index')
            ->with('success', 'Товар успешно удалён');
    }

    /**
     * Delete a specific media file from the product.
     */
    public function deleteMedia(Product $product, Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'media_id' => 'required|integer|exists:media,id',
        ]);

        $media = $product->media()->findOrFail($validated['media_id']);
        $media->delete();

        return response()->json(['success' => true]);
    }
}
