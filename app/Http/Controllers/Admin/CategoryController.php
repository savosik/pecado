<?php

namespace App\Http\Controllers\Admin;

use App\Models\Category;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;

class CategoryController extends AdminController
{
    /**
     * Display a listing of the categories.
     */
    public function index(Request $request): Response
    {
        $query = Category::query()
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

        // Явно добавляем parent в сериализацию (NodeTrait не сериализует parent автоматически)
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

        return redirect()
            ->route('admin.categories.index')
            ->with('success', 'Категория успешно создана');
    }

    /**
     * Show the form for editing the specified category.
     */
    public function edit(Category $category): Response
    {
        $category->load(['parent', 'media', 'tags']);

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
            ],
            'categories' => Category::select('id', 'name', 'parent_id')
                ->where('id', '!=', $category->id) // Исключаем текущую категорию
                ->orderBy('name')
                ->get(),
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

        return redirect()
            ->route('admin.categories.index')
            ->with('success', 'Категория успешно обновлена');
    }

    /**
     * Remove the specified category from storage.
     */
    public function destroy(Category $category): RedirectResponse
    {
        $category->delete();

        return redirect()
            ->route('admin.categories.index')
            ->with('success', 'Категория успешно удалена');
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
}
