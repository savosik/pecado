<?php

namespace App\Http\Controllers\Admin;

use App\Models\Product;
use App\Models\Brand;
use App\Models\Category;

use App\Models\SizeChart;
use App\Models\Warehouse;
use App\Models\Attribute;
use App\Models\Certificate;
use App\Models\ProductSelection;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;

class ProductController extends AdminController
{
    use RedirectsAfterSave;

    /**
     * Display a listing of the products.
     */
    public function index(Request $request): Response
    {
        $query = Product::query()
            ->with(['brand', 'model', 'category', 'media', 'tags']);

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
            'categoryTree' => Category::withCount('products')
                ->defaultOrder()
                ->get()
                ->toTree(),
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
            'category_id' => 'nullable|exists:categories,id',
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
            'barcodes' => 'nullable|array',
            'barcodes.*' => 'string|max:255',
            'image' => 'nullable|image|max:10240',
            'additional_images' => 'nullable|array',
            'additional_images.*' => 'image|max:10240',
            'video' => 'nullable|mimes:mp4,webm,mov|max:51200',
            'tags' => 'nullable|array',

            'certificates' => 'nullable|array',
            'certificates.*' => 'exists:certificates,id',

            'attributes' => 'nullable|array',
            'attributes.*.attribute_id' => 'required|exists:attributes,id',
            'attributes.*.attribute_value_id' => 'nullable|exists:attribute_values,id',
            'attributes.*.text_value' => 'nullable|string',
            'attributes.*.number_value' => 'nullable|numeric',
            'attributes.*.boolean_value' => 'nullable|boolean',
        ]);

        // Генерация slug если не указан
        if (empty($validated['slug'])) {
            $validated['slug'] = \Illuminate\Support\Str::slug(\App\Helpers\SearchHelper::transliterate($validated['name']));
        }

        // Устанавливаем основной штрихкод как первый из списка для совместимости
        if (!empty($validated['barcodes'])) {
            $validated['barcode'] = $validated['barcodes'][0];
        }

        $product = Product::create($validated);

        // Сохраняем все штрихкоды в связанную таблицу
        if (!empty($validated['barcodes'])) {
            foreach ($validated['barcodes'] as $barcode) {
                $product->barcodes()->create(['barcode' => $barcode]);
            }
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

        // Теги
        if ($request->has('tags')) {
            $product->syncTags($request->tags);
        }

        // Сертификаты
        if (isset($validated['certificates'])) {
            $product->certificates()->sync($validated['certificates']);
        }

        // Сохранение атрибутов
        if (isset($validated['attributes'])) {
            foreach ($validated['attributes'] as $attr) {
                $product->attributeValues()->create([
                    'attribute_id' => $attr['attribute_id'],
                    'attribute_value_id' => $attr['attribute_value_id'] ?? null,
                    'text_value' => $attr['text_value'] ?? null,
                    'number_value' => $attr['number_value'] ?? null,
                    'boolean_value' => $attr['boolean_value'] ?? null,
                ]);
            }
        }

        return $this->redirectAfterSave($request, 'admin.products.index', 'admin.products.edit', $product, 'Товар успешно создан');
    }

    /**
     * Show the form for editing the specified product.
     */
    public function edit(Product $product): Response
    {
        $product->load([
            'brand', 
            'model', 
            'category', 
            'sizeChart', 
            'media', 
            'tags', 
            'barcodes', 
            'certificates',
            'warehouses',
            'attributeValues.attribute',
            'productSelections',
        ]);

        return Inertia::render('Admin/Pages/Products/Edit', [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'base_price' => $product->base_price,
                'category_id' => $product->category_id,
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
                'barcodes' => $product->barcodes->pluck('barcode')->toArray(),
                'tnved' => $product->tnved,
                'is_new' => $product->is_new,
                'is_bestseller' => $product->is_bestseller,
                'is_marked' => $product->is_marked,
                'is_liquidation' => $product->is_liquidation,
                'for_marketplaces' => $product->for_marketplaces,
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
                'tags' => $product->tags->map(function ($tag) {
                    return [
                        'value' => $tag->name,
                        'label' => $tag->name,
                    ];
                }),
                'certificates' => $product->certificates->map(function ($cert) {
                    return [
                        'id' => $cert->id,
                        'name' => $cert->name,
                        'type' => $cert->type,
                        'status' => $cert->expires_at && $cert->expires_at->isPast() ? 'expired' : 'active',
                    ];
                }),
                'warehouses' => $product->warehouses->map(function ($warehouse) {
                    return [
                        'id' => $warehouse->id,
                        'name' => $warehouse->name,
                        'quantity' => $warehouse->pivot->quantity,
                    ];
                }),
                'attributes' => $product->attributeValues->map(function ($attrValue) {
                    return [
                        'id' => $attrValue->id,
                        'attribute_id' => $attrValue->attribute_id,
                        'attribute_name' => $attrValue->attribute->name,
                        'attribute_value_id' => $attrValue->attribute_value_id,
                        'text_value' => $attrValue->text_value,
                        'number_value' => $attrValue->number_value,
                        'boolean_value' => $attrValue->boolean_value,
                    ];
                   }),
                'product_selections' => $product->productSelections->pluck('id')->toArray(),
            ],
            'brands' => Brand::select('id', 'name')->orderBy('name')->get(),
            'categoryTree' => Category::withCount('products')
                ->defaultOrder()
                ->get()
                ->toTree(),
            'modelName' => $product->model?->name,
            'sizeCharts' => SizeChart::select('id', 'name')->orderBy('name')->get(),
            'warehouses' => Warehouse::select('id', 'name')->orderBy('name')->get(),
            'attributes' => Attribute::with('values')->orderBy('name')->get(),
            'certificates' => Certificate::select('id', 'name', 'type')->orderBy('name')->get(),
            'productSelections' => ProductSelection::select('id', 'name')->orderBy('name')->get(),
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
            'category_id' => 'nullable|exists:categories,id',
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
            'barcodes' => 'nullable|array',
            'barcodes.*' => 'string|max:255',
            'image' => 'nullable|image|max:10240',
            'additional_images' => 'nullable|array',
            'additional_images.*' => 'image|max:10240',
            'video' => 'nullable|mimes:mp4,webm,mov|max:51200',
            'tags' => 'nullable|array',

            'certificates' => 'nullable|array',
            'certificates.*' => 'exists:certificates,id',

            'product_selections' => 'nullable|array',
            'product_selections.*' => 'exists:product_selections,id',
            
            'warehouses' => 'nullable|array',
            'warehouses.*.id' => 'required|exists:warehouses,id',
            'warehouses.*.quantity' => 'required|integer|min:0',
            
            'attributes' => 'nullable|array',
            'attributes.*.attribute_id' => 'required|exists:attributes,id',
            'attributes.*.attribute_value_id' => 'nullable|exists:attribute_values,id',
            'attributes.*.text_value' => 'nullable|string',
            'attributes.*.number_value' => 'nullable|numeric',
            'attributes.*.boolean_value' => 'nullable|boolean',
        ]);

        // Генерация slug если не указан
        if (empty($validated['slug'])) {
            $validated['slug'] = \Illuminate\Support\Str::slug(\App\Helpers\SearchHelper::transliterate($validated['name']));
        }

        // Устанавливаем основной штрихкод как первый из списка для совместимости
        if (isset($validated['barcodes'])) {
            $validated['barcode'] = !empty($validated['barcodes']) ? $validated['barcodes'][0] : null;
        }

        $product->update($validated);

        // Синхронизируем штрихкоды
        if (isset($validated['barcodes'])) {
            $product->barcodes()->delete();
            foreach ($validated['barcodes'] as $barcode) {
                if (!empty($barcode)) {
                    $product->barcodes()->create(['barcode' => $barcode]);
                }
            }
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

        // Теги
        if ($request->has('tags')) {
            $product->syncTags($request->tags);
        }

        // Синхронизация сертификатов
        if (isset($validated['certificates'])) {
            $product->certificates()->sync($validated['certificates']);
        } else {
            $product->certificates()->detach();
        }
        
        // Синхронизация складов с количеством
        if (isset($validated['warehouses'])) {
            $warehouseData = [];
            foreach ($validated['warehouses'] as $warehouse) {
                $warehouseData[$warehouse['id']] = ['quantity' => $warehouse['quantity']];
            }
            $product->warehouses()->sync($warehouseData);
        } else {
            $product->warehouses()->detach();
        }

        // Синхронизация подборок
        if (isset($validated['product_selections'])) {
            $product->productSelections()->sync($validated['product_selections']);
        } else {
            $product->productSelections()->detach();
        }
        
        // Синхронизация атрибутов
        if (isset($validated['attributes'])) {
            // Удалить старые значения атрибутов
            $product->attributeValues()->delete();
            
            // Создать новые
            foreach ($validated['attributes'] as $attr) {
                $product->attributeValues()->create([
                    'attribute_id' => $attr['attribute_id'],
                    'attribute_value_id' => $attr['attribute_value_id'] ?? null,
                    'text_value' => $attr['text_value'] ?? null,
                    'number_value' => $attr['number_value'] ?? null,
                    'boolean_value' => $attr['boolean_value'] ?? null,
                ]);
            }
        } else {
            $product->attributeValues()->delete();
        }

        return $this->redirectAfterSave($request, 'admin.products.index', 'admin.products.edit', $product, 'Товар успешно обновлён');
    }

    /**
     * Remove the specified product from storage.
     */
    public function destroy(Product $product): RedirectResponse
    {
        $product->delete();

        return redirect()->route('admin.products.index')->with('success', 'Товар успешно удалён');
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

    /**
     * Search products for selector.
     */
    public function search(Request $request): \Illuminate\Http\JsonResponse
    {
        $query = $request->input('query');

        if (!$query) {
            return response()->json([]);
        }

        $products = Product::search($query)
            ->query(fn ($q) => $q->with(['media', 'barcodes', 'brand'])->limit(20))
            ->get()
            ->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'image_url' => $product->getFirstMediaUrl('main'),
                    'price' => $product->base_price,
                    'barcode' => $product->barcode, // Main barcode
                    'barcodes' => $product->barcodes->pluck('barcode')->toArray(), // All barcodes
                    'brand_name' => $product->brand ? $product->brand->name : null,
                ];
            });

        return response()->json($products);
    }
}
