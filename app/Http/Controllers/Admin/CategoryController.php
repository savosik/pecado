<?php

namespace App\Http\Controllers\Admin;

use App\Models\Attribute;
use App\Models\Category;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;

class CategoryController extends AdminController
{
    use RedirectsAfterSave;

    /**
     * Display a listing of the categories.
     */
    public function index(Request $request): Response
    {
        $viewMode = $request->input('view', 'list');

        if ($viewMode === 'tree') {
            $categories = Category::withCount('products')
                ->with(['media', 'tags', 'parent'])
                ->defaultOrder()
                ->get()
                ->toTree();

            // Ручная установка parent аттрибута не нужна для toTree,
            // так как мы получаем полную иерархию через relation children.
            // Но media и tags мы загружаем.

            return Inertia::render('Admin/Pages/Categories/Index', [
                'categories' => $categories,
                'filters' => [
                    'search' => $request->input('search'),
                    'view' => 'tree',
                ],
            ]);
        }

        $query = Category::query()
            ->withCount('products')
            ->with(['parent', 'media', 'tags']);

        // Поиск
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('short_description', 'like', "%{$search}%");
            });
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');
        
        $allowedSortFields = ['id', 'name', 'created_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Пагинация
        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100); // Ограничение от 5 до 100

        $categories = $query->paginate($perPage)->withQueryString();

        // Явно добавляем parent в сериализацию
        $categories->through(function ($category) {
            $category->setAttribute('parent', $category->parent);
            return $category;
        });

        return Inertia::render('Admin/Pages/Categories/Index', [
            'categories' => $categories,
            'filters' => [
                'search' => $search,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
                'view' => 'list',
            ],
        ]);
    }

    /**
     * Show the form for creating a new category.
     */
    public function create(): Response
    {
        return Inertia::render('Admin/Pages/Categories/Create', [
            'categories' => Category::select('id', 'name', 'parent_id')->orderBy('name')->get(),
            'availableAttributes' => Attribute::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    /**
     * Store a newly created category in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|exists:categories,id',
            'external_id' => 'nullable|string|max:255',
            'short_description' => 'nullable|string',
            'description' => 'nullable|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'icon' => 'nullable|image|max:5120',
            'tags' => 'nullable|array',
            'attribute_ids' => 'nullable|array',
            'attribute_ids.*' => 'exists:attributes,id',
        ]);

        $category = Category::create($validated);

        // Загрузка иконки
        if ($request->hasFile('icon')) {
            $category->addMediaFromRequest('icon')
                ->toMediaCollection('icon');
        }

        // Теги - нормализация: извлекаем строки из объектов если нужно
        if ($request->has('tags')) {
            $tagNames = collect($request->tags)->map(function ($tag) {
                if (is_array($tag)) {
                    // Если тег - объект с вложенным name
                    $name = $tag['name'] ?? $tag;
                    if (is_array($name)) {
                        // Локализованное имя {ru: "...", en: "..."}
                        return $name['ru'] ?? $name['en'] ?? array_values($name)[0] ?? '';
                    }
                    return $name;
                }
                return $tag;
            })->filter()->values()->toArray();
            $category->syncTags($tagNames);
        }

        if (!empty($validated['attribute_ids'])) {
            $category->attributes()->sync($validated['attribute_ids']);
        }

        return $this->redirectAfterSave($request, 'admin.categories.index', 'admin.categories.edit', $category, 'Категория успешно создана');
    }

    /**
     * Show the form for editing the specified category.
     */
    public function edit(Category $category): Response
    {
        $category->load(['parent', 'media', 'tags', 'attributes:id,name']);

        return Inertia::render('Admin/Pages/Categories/Edit', [
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'parent_id' => $category->parent_id,
                'external_id' => $category->external_id,
                'short_description' => $category->short_description,
                'description' => $category->description,
                'meta_title' => $category->meta_title,
                'meta_description' => $category->meta_description,
                'icon_url' => $category->getFirstMediaUrl('icon'),
                'icon_id' => $category->getFirstMedia('icon')?->id,
                'tags' => $category->tags,
                'attributes' => $category->attributes,
            ],
            'categories' => Category::select('id', 'name', 'parent_id')
                ->where('id', '!=', $category->id)
                ->orderBy('name')
                ->get(),
            'availableAttributes' => Attribute::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    /**
     * Update the specified category in storage.
     */
    public function update(Request $request, Category $category): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|exists:categories,id',
            'external_id' => 'nullable|string|max:255',
            'short_description' => 'nullable|string',
            'description' => 'nullable|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'icon' => 'nullable|image|max:5120',
            'tags' => 'nullable|array',
            'attribute_ids' => 'nullable|array',
            'attribute_ids.*' => 'exists:attributes,id',
        ]);

        // Предотвращаем установку категории самой себе в качестве родителя
        if (isset($validated['parent_id']) && $validated['parent_id'] == $category->id) {
            return redirect()
                ->back()
                ->withErrors(['parent_id' => 'Категория не может быть родителем самой себя']);
        }

        $category->update($validated);

        // Обновление иконки
        if ($request->hasFile('icon')) {
            $category->clearMediaCollection('icon');
            $category->addMediaFromRequest('icon')
                ->toMediaCollection('icon');
        }

        // Теги - нормализация: извлекаем строки из объектов если нужно
        if ($request->has('tags')) {
            $tagNames = collect($request->tags)->map(function ($tag) {
                if (is_array($tag)) {
                    // Если тег - объект с вложенным name
                    $name = $tag['name'] ?? $tag;
                    if (is_array($name)) {
                        // Локализованное имя {ru: "...", en: "..."}
                        return $name['ru'] ?? $name['en'] ?? array_values($name)[0] ?? '';
                    }
                    return $name;
                }
                return $tag;
            })->filter()->values()->toArray();
            $category->syncTags($tagNames);
        }

        $category->attributes()->sync($validated['attribute_ids'] ?? []);

        return $this->redirectAfterSave($request, 'admin.categories.index', 'admin.categories.edit', $category, 'Категория успешно обновлена');
    }

    /**
     * Remove the specified category from storage.
     */
    public function destroy(Category $category): RedirectResponse
    {
        $category->delete();

        return redirect()->route('admin.categories.index')->with('success', 'Категория успешно удалена');
    }

    /**
     * Delete a specific media file from the category.
     */
    public function deleteMedia(Category $category, Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'media_id' => 'required|integer|exists:media,id',
        ]);

        $media = $category->media()->findOrFail($validated['media_id']);
        $media->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Get attributes for given category IDs (AJAX endpoint).
     */
    public function attributes(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'category_ids' => 'required|array',
            'category_ids.*' => 'integer|exists:categories,id',
        ]);

        $categoryIds = $request->input('category_ids', []);

        // Получаем уникальные атрибуты для выбранных категорий
        $attributes = Attribute::whereHas('categories', function ($query) use ($categoryIds) {
            $query->whereIn('categories.id', $categoryIds);
        })
            ->with(['values' => function ($query) {
                $query->orderBy('sort_order');
            }])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function ($attr) {
                return [
                    'id' => $attr->id,
                    'name' => $attr->name,
                    'slug' => $attr->slug,
                    'type' => $attr->type,
                    'unit' => $attr->unit,
                    'is_filterable' => $attr->is_filterable,
                    'values' => $attr->values->map(function ($v) {
                        return [
                            'id' => $v->id,
                            'value' => $v->value,
                        ];
                    }),
                ];
            });

        return response()->json($attributes);
    }

    /**
     * Search categories for entity selector (AJAX endpoint).
     */
    public function search(Request $request): \Illuminate\Http\JsonResponse
    {
        $query = Category::query();

        if ($search = $request->input('query')) {
            $query->where('name', 'like', "%{$search}%");
        }

        $categories = $query->select('id', 'name')
            ->limit(20)
            ->get()
            ->map(function ($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                ];
            });

        return response()->json($categories);
    }
}
