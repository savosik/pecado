<?php

namespace App\Http\Controllers\Admin;

use App\Models\Brand;
use App\Enums\BrandCategory;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;

class BrandController extends AdminController
{
    /**
     * Display a listing of the brands.
     */
    public function index(Request $request): Response
    {
        $query = Brand::query()
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
        
        $allowedSortFields = ['id', 'name', 'created_at', 'slug'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Пагинация
        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100);

        $brands = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/Pages/Brands/Index', [
            'brands' => $brands,
            'filters' => [
                'search' => $search,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
            ],
        ]);
    }

    /**
     * Show the form for creating a new brand.
     */
    public function create(): Response
    {
        return Inertia::render('Admin/Pages/Brands/Create', [
            'brands' => Brand::select('id', 'name')->orderBy('name')->get(),
            'categories' => collect(BrandCategory::cases())->map(fn($c) => ['value' => $c->value, 'label' => $c->label()]),
        ]);
    }

    /**
     * Store a newly created brand in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:brands,slug',
            'parent_id' => 'nullable|exists:brands,id',
            'external_id' => 'nullable|string|max:255',
            'short_description' => 'nullable|string',
            'description' => 'nullable|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'logo' => 'nullable|image|max:5120',
            'tags' => 'nullable|array',
            'category' => ['required', Rule::enum(BrandCategory::class)],
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = \Illuminate\Support\Str::slug($validated['name']);
        }

        $brand = Brand::create($validated);

        // Загрузка логотипа
        if ($request->hasFile('logo')) {
            $brand->addMediaFromRequest('logo')
                ->toMediaCollection('logo');
        }

        // Теги
        if ($request->has('tags')) {
             $tagNames = collect($request->tags)->map(function ($tag) {
                if (is_array($tag)) {
                    $name = $tag['name'] ?? $tag;
                    if (is_array($name)) {
                        return $name['ru'] ?? $name['en'] ?? array_values($name)[0] ?? '';
                    }
                    return $name;
                }
                return $tag;
            })->filter()->values()->toArray();
            $brand->syncTags($tagNames);
        }

        return redirect()
            ->route('admin.brands.index')
            ->with('success', 'Бренд успешно создан');
    }

    /**
     * Show the form for editing the specified brand.
     */
    public function edit(Brand $brand): Response
    {
        $brand->load(['parent', 'media', 'tags']);

        return Inertia::render('Admin/Pages/Brands/Edit', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'slug' => $brand->slug,
                'parent_id' => $brand->parent_id,
                'category' => $brand->category->value,
                'external_id' => $brand->external_id,
                'short_description' => $brand->short_description,
                'description' => $brand->description,
                'meta_title' => $brand->meta_title,
                'meta_description' => $brand->meta_description,
                'logo_url' => $brand->getFirstMediaUrl('logo'),
                'logo_id' => $brand->getFirstMedia('logo')?->id,
                'tags' => $brand->tags,
            ],
            'brands' => Brand::select('id', 'name')
                ->where('id', '!=', $brand->id)
                ->orderBy('name')
                ->get(),
            'categories' => collect(BrandCategory::cases())->map(fn($c) => ['value' => $c->value, 'label' => $c->label()]),
        ]);
    }

    /**
     * Update the specified brand in storage.
     */
    public function update(Request $request, Brand $brand): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:brands,slug,' . $brand->id,
            'parent_id' => 'nullable|exists:brands,id',
            'external_id' => 'nullable|string|max:255',
            'short_description' => 'nullable|string',
            'description' => 'nullable|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'logo' => 'nullable|image|max:5120',
            'tags' => 'nullable|array',
            'category' => ['required', Rule::enum(BrandCategory::class)],
        ]);

         if (isset($validated['parent_id']) && $validated['parent_id'] == $brand->id) {
            return redirect()
                ->back()
                ->withErrors(['parent_id' => 'Бренд не может быть родителем самого себя']);
        }
        
        if (empty($validated['slug'])) {
            $validated['slug'] = \Illuminate\Support\Str::slug($validated['name']);
        }

        $brand->update($validated);

        // Обновление логотипа
        if ($request->hasFile('logo')) {
            $brand->clearMediaCollection('logo');
            $brand->addMediaFromRequest('logo')
                ->toMediaCollection('logo');
        }

         // Теги
        if ($request->has('tags')) {
             $tagNames = collect($request->tags)->map(function ($tag) {
                if (is_array($tag)) {
                    $name = $tag['name'] ?? $tag;
                    if (is_array($name)) {
                        return $name['ru'] ?? $name['en'] ?? array_values($name)[0] ?? '';
                    }
                    return $name;
                }
                return $tag;
            })->filter()->values()->toArray();
            $brand->syncTags($tagNames);
        }

        return redirect()
            ->route('admin.brands.index')
            ->with('success', 'Бренд успешно обновлен');
    }

    /**
     * Remove the specified brand from storage.
     */
    public function destroy(Brand $brand): RedirectResponse
    {
        $brand->delete();

        return redirect()
            ->route('admin.brands.index')
            ->with('success', 'Бренд успешно удален');
    }
    
     /**
     * Delete a specific media file from the brand (logo).
     */
    public function deleteMedia(Brand $brand, Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'media_id' => 'required|integer|exists:media,id',
        ]);

        $media = $brand->media()->findOrFail($validated['media_id']);
        $media->delete();

        return response()->json(['success' => true]);
    }
}
