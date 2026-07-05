<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;
use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Certificate;
use App\Models\Product;
use App\Models\ProductSelection;
use App\Models\Scopes\HiddenScope;
use App\Models\SizeChart;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends AdminController
{
    use RedirectsAfterSave;

    /**
     * Display a listing of the products.
     */
    public function index(Request $request): Response
    {
        $query = $this->buildIndexQuery($request);

        // Пагинация
        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100); // Ограничение от 5 до 100

        $products = $query->paginate($perPage)->withQueryString();

        // Подписи выбранных элементов мультивыборов — чтобы чипы рендерились
        // сразу после перезагрузки страницы (не дожидаясь async-поиска).
        $brandIds = array_filter(array_map('intval', (array) $request->input('brands', [])));
        $categoryIds = array_filter(array_map('intval', (array) $request->input('categories', [])));
        $tagIds = array_filter(array_map('intval', (array) $request->input('tags', [])));

        return Inertia::render('Admin/Pages/Products/Index', [
            'products' => $products,
            'filters' => [
                'search' => $request->input('search'),
                'sort_by' => $request->input('sort_by', 'id'),
                'sort_order' => $request->input('sort_order', 'desc'),
                'per_page' => $perPage,
                'brands' => $brandIds,
                'categories' => $categoryIds,
                'tags' => $tagIds,
                'images' => $request->input('images'),
                'description_filter' => $request->input('description_filter'),
                'hidden' => $request->input('hidden'),
                'price_min' => $request->input('price_min'),
                'price_max' => $request->input('price_max'),
                'flags' => array_values((array) $request->input('flags', [])),
                'stock' => $request->input('stock'),
                'brands_selected' => empty($brandIds) ? [] : Brand::whereIn('id', $brandIds)->get(['id', 'name']),
                'categories_selected' => empty($categoryIds) ? [] : Category::whereIn('id', $categoryIds)->get(['id', 'name']),
                'tags_selected' => empty($tagIds) ? [] : \Spatie\Tags\Tag::whereIn('id', $tagIds)->get()->map(fn ($t) => ['id' => $t->id, 'name' => $t->name]),
            ],
        ]);
    }

    /**
     * Экспорт списка товаров в Excel с учётом текущих фильтров.
     */
    public function export(Request $request, \App\Services\SimpleXlsxExporter $exporter): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $query = $this->buildIndexQuery($request);

        $headers = ['ID', 'Название', 'SKU', 'Код', 'Бренд', 'Категория', 'Цена, ₽', 'Есть картинка', 'Теги', 'Скрыт', 'Есть описание'];

        $rows = (function () use ($query) {
            foreach ($query->lazy(500) as $product) {
                $tags = $product->tags
                    ->pluck('name')
                    ->map(fn ($n) => is_array($n) ? ($n['ru'] ?? reset($n)) : $n)
                    ->implode(', ');

                yield [
                    $product->id,
                    $product->name,
                    $product->sku,
                    $product->code,
                    $product->brand?->name,
                    $product->category?->name,
                    (float) $product->base_price,
                    $product->media->isNotEmpty() ? 'да' : 'нет',
                    $tags,
                    $product->hidden ? 'да' : 'нет',
                    filled($product->description) ? 'да' : 'нет',
                ];
            }
        })();

        $withoutImages = $request->input('images') === 'without' || $request->boolean('without_images');
        $suffix = $withoutImages ? '_bez_kartinok' : '';
        $filename = 'tovary'.$suffix.'_'.now()->format('Ymd_His');

        return $exporter->stream($filename, $headers, $rows, 'Товары');
    }

    /**
     * Общий построитель запроса для списка и экспорта товаров.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Product>
     */
    private function buildIndexQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $query = Product::withoutGlobalScope(HiddenScope::class)
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

        // Фильтр по брендам
        $brandIds = array_filter(array_map('intval', (array) $request->input('brands', [])));
        if (! empty($brandIds)) {
            $query->inBrands($brandIds);
        }

        // Фильтр по категориям (с учётом вложенных подкатегорий)
        $categoryIds = array_filter(array_map('intval', (array) $request->input('categories', [])));
        if (! empty($categoryIds)) {
            $query->inCategories($categoryIds);
        }

        // Фильтр по тегам
        $tagIds = array_filter(array_map('intval', (array) $request->input('tags', [])));
        if (! empty($tagIds)) {
            $query->whereHas('tags', function ($q) use ($tagIds) {
                $q->whereIn('tags.id', $tagIds);
            });
        }

        // Фильтр по наличию изображений.
        // Обратная совместимость: старый параметр without_images=1 → images=without.
        $images = $request->input('images');
        if ($request->boolean('without_images')) {
            $images = 'without';
        }
        if ($images === 'with') {
            $query->whereHas('media');
        } elseif ($images === 'without') {
            $query->whereDoesntHave('media');
        }

        // Фильтр по наличию описания
        $descriptionFilter = $request->input('description_filter');
        if ($descriptionFilter === 'with') {
            $query->whereNotNull('description')->where('description', '<>', '');
        } elseif ($descriptionFilter === 'without') {
            $query->where(function ($q) {
                $q->whereNull('description')->orWhere('description', '=', '');
            });
        }

        // Фильтр по видимости на сайте (скрыт/видим)
        $hidden = $request->input('hidden');
        if ($hidden === 'yes') {
            $query->where('hidden', true);
        } elseif ($hidden === 'no') {
            $query->where('hidden', false);
        }

        // Фильтр по диапазону цены
        $priceMin = $request->input('price_min');
        $priceMax = $request->input('price_max');
        $query->byPrice(
            is_numeric($priceMin) ? (float) $priceMin : null,
            is_numeric($priceMax) ? (float) $priceMax : null,
        );

        // Фильтр по флагам-бейджам (AND-семантика: товар удовлетворяет всем выбранным)
        $allowedFlags = ['is_new', 'is_bestseller', 'is_liquidation', 'is_marked', 'for_marketplaces'];
        foreach ((array) $request->input('flags', []) as $flag) {
            if (in_array($flag, $allowedFlags, true)) {
                $query->where($flag, true);
            }
        }

        // Фильтр по наличию на складе.
        // Упрощённо, без региональной логики (в админке нет региона пользователя):
        // считаем «в наличии», если есть остаток > 0 на любом складе.
        $stock = $request->input('stock');
        if ($stock === 'in') {
            $query->whereHas('warehouses', function ($q) {
                $q->where('product_warehouse.quantity', '>', 0);
            });
        } elseif ($stock === 'out') {
            $query->whereDoesntHave('warehouses', function ($q) {
                $q->where('product_warehouse.quantity', '>', 0);
            });
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');

        $allowedSortFields = ['id', 'name', 'base_price', 'created_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        return $query;
    }

    /**
     * Display the specified product.
     */
    public function show($id): Response
    {
        $product = Product::withoutGlobalScope(HiddenScope::class)->findOrFail($id);

        $product->load([
            'brand',
            'model',
            'category',
            'media',
            'tags',
            'barcodes',
            'warehouses',
            'attributeValues.attribute',
        ]);

        return Inertia::render('Admin/Pages/Products/Show', [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'sku' => $product->sku,
                'code' => $product->code,
                'external_id' => $product->external_id,
                'variant_name' => $product->variant_name,
                'base_price' => $product->base_price,
                'hidden' => (bool) $product->hidden,
                'is_new' => (bool) $product->is_new,
                'is_bestseller' => (bool) $product->is_bestseller,
                'is_marked' => (bool) $product->is_marked,
                'is_liquidation' => (bool) $product->is_liquidation,
                'for_marketplaces' => (bool) $product->for_marketplaces,
                'weight_gross' => $product->weight_gross,
                'weight_net' => $product->weight_net,
                'width' => $product->width,
                'height' => $product->height,
                'depth' => $product->depth,
                'erp_created_at' => $product->erp_created_at?->format('d.m.Y H:i'),
                'erp_updated_at' => $product->erp_updated_at?->format('d.m.Y H:i'),
                'brand' => $product->brand ? ['id' => $product->brand->id, 'name' => $product->brand->name] : null,
                'category' => $product->category ? ['id' => $product->category->id, 'name' => $product->category->name] : null,
                'model' => $product->model ? ['id' => $product->model->id, 'name' => $product->model->name] : null,
                'main_image' => $product->getFirstMediaUrl('main'),
                'additional_media' => $product->getMedia('additional')->map(fn ($m) => ['id' => $m->id, 'url' => $m->getUrl()]),
                'tags' => $product->tags->pluck('name'),
                'barcodes' => $product->barcodes->pluck('barcode'),
                'warehouses' => $product->warehouses->map(fn ($w) => ['name' => $w->name, 'quantity' => $w->pivot->quantity]),
                'attributes' => $product->attributeValues->map(fn ($av) => [
                    'attribute_name' => $av->attribute->name,
                    'value' => $av->text_value ?? ($av->number_value !== null ? (string) $av->number_value : ($av->boolean_value !== null ? ($av->boolean_value ? 'Да' : 'Нет') : null)),
                ])->filter(fn ($av) => $av['value'] !== null)->values(),
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
            'rich_content' => 'nullable|json',
            'short_description' => 'nullable|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'meta_keywords' => 'nullable|string|max:500',
            'sku' => 'nullable|string|max:255',
            'variant_name' => 'nullable|string|max:255',
            'code' => 'nullable|string|max:255',
            'external_id' => 'nullable|string|max:255',
            'sex_opt_id' => 'nullable|string|max:255',
            'url' => 'nullable|url|max:255',
            'barcode' => 'nullable|string|max:255',
            'tnved' => 'nullable|string|max:255',
            'weight_gross' => 'nullable|numeric|min:0',
            'weight_net' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'depth' => 'nullable|numeric|min:0',
            'hs_code' => 'nullable|string|max:20',
            'abc_xyz' => 'nullable|string|max:5',
            'turnover' => 'nullable|numeric|min:0',
            'is_new' => 'boolean',
            'is_bestseller' => 'boolean',
            'is_marked' => 'boolean',
            'is_liquidation' => 'boolean',
            'for_marketplaces' => 'boolean',
            'hidden' => 'boolean',
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
        if (! empty($validated['barcodes'])) {
            $validated['barcode'] = $validated['barcodes'][0];
        }

        // Декодируем rich_content из JSON-строки в массив для корректной работы с кастом
        if (isset($validated['rich_content'])) {
            $validated['rich_content'] = ! empty($validated['rich_content'])
                ? json_decode($validated['rich_content'], true)
                : null;
        }

        $product = Product::create($validated);

        // Сохраняем все штрихкоды в связанную таблицу
        if (! empty($validated['barcodes'])) {
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
    public function edit($id): Response
    {
        $product = Product::withoutGlobalScope(HiddenScope::class)->findOrFail($id);

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
                'rich_content' => $product->rich_content,
                'short_description' => $product->short_description,
                'meta_title' => $product->meta_title,
                'meta_description' => $product->meta_description,
                'meta_keywords' => $product->meta_keywords,
                'sku' => $product->sku,
                'variant_name' => $product->variant_name,
                'code' => $product->code,
                'external_id' => $product->external_id,
                'sex_opt_id' => $product->sex_opt_id,
                'url' => $product->url,
                'barcodes' => $product->barcodes->pluck('barcode')->toArray(),
                'tnved' => $product->tnved,
                'weight_gross' => $product->weight_gross,
                'weight_net' => $product->weight_net,
                'width' => $product->width,
                'height' => $product->height,
                'depth' => $product->depth,
                'hs_code' => $product->hs_code,
                'abc_xyz' => $product->abc_xyz,
                'turnover' => $product->turnover,
                'erp_created_at' => $product->erp_created_at?->format('d.m.Y H:i'),
                'erp_updated_at' => $product->erp_updated_at?->format('d.m.Y H:i'),
                'is_new' => $product->is_new,
                'is_bestseller' => $product->is_bestseller,
                'is_marked' => $product->is_marked,
                'is_liquidation' => $product->is_liquidation,
                'for_marketplaces' => $product->for_marketplaces,
                'hidden' => $product->hidden,
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
    public function update(Request $request, $id): RedirectResponse
    {
        $product = Product::withoutGlobalScope(HiddenScope::class)->findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:products,slug,'.$product->id,
            'base_price' => 'required|numeric|min:0',
            'category_id' => 'nullable|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'model_id' => 'nullable|exists:product_models,id',
            'size_chart_id' => 'nullable|exists:size_charts,id',
            'description' => 'nullable|string',
            'description_html' => 'nullable|string',
            'rich_content' => 'nullable|json',
            'short_description' => 'nullable|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'meta_keywords' => 'nullable|string|max:500',
            'sku' => 'nullable|string|max:255',
            'variant_name' => 'nullable|string|max:255',
            'code' => 'nullable|string|max:255',
            'external_id' => 'nullable|string|max:255',
            'sex_opt_id' => 'nullable|string|max:255',
            'url' => 'nullable|url|max:255',
            'barcode' => 'nullable|string|max:255',
            'tnved' => 'nullable|string|max:255',
            'weight_gross' => 'nullable|numeric|min:0',
            'weight_net' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'depth' => 'nullable|numeric|min:0',
            'hs_code' => 'nullable|string|max:20',
            'abc_xyz' => 'nullable|string|max:5',
            'turnover' => 'nullable|numeric|min:0',
            'is_new' => 'boolean',
            'is_bestseller' => 'boolean',
            'is_marked' => 'boolean',
            'is_liquidation' => 'boolean',
            'for_marketplaces' => 'boolean',
            'hidden' => 'boolean',
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
            $validated['barcode'] = ! empty($validated['barcodes']) ? $validated['barcodes'][0] : null;
        }

        // Декодируем rich_content из JSON-строки в массив для корректной работы с кастом
        if (isset($validated['rich_content'])) {
            $validated['rich_content'] = ! empty($validated['rich_content'])
                ? json_decode($validated['rich_content'], true)
                : null;
        }

        $product->update($validated);

        // Синхронизируем штрихкоды
        if (isset($validated['barcodes'])) {
            $product->barcodes()->delete();
            foreach ($validated['barcodes'] as $barcode) {
                if (! empty($barcode)) {
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
    public function destroy($id): RedirectResponse
    {
        $product = Product::withoutGlobalScope(HiddenScope::class)->findOrFail($id);
        $product->delete();

        return redirect()->route('admin.products.index')->with('success', 'Товар успешно удалён');
    }

    /**
     * Delete a specific media file from the product.
     */
    public function deleteMedia($id, Request $request): \Illuminate\Http\JsonResponse
    {
        $product = Product::withoutGlobalScope(HiddenScope::class)->findOrFail($id);

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

        if (! $query) {
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
