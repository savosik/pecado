<?php

namespace App\Http\Controllers\Admin;

use App\Models\Attribute;
use App\Models\Category;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class AttributeController extends AdminController
{
    /**
     * Display a listing of the attributes.
     */
    public function index(Request $request): Response
    {
        $query = Attribute::query()->withCount('values')->with('categories:id,name');

        // Поиск
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'sort_order');
        $sortOrder = $request->input('sort_order', 'asc');
        
        $allowedSortFields = ['id', 'name', 'slug', 'type', 'sort_order', 'created_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Пагинация
        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100);

        $attributes = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/Pages/Attributes/Index', [
            'attributes' => $attributes,
            'filters' => [
                'search' => $search,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
            ],
        ]);
    }

    /**
     * Show the form for creating a new attribute.
     */
    public function create(): Response
    {
        return Inertia::render('Admin/Pages/Attributes/Create', [
            'types' => [
                ['value' => 'string', 'label' => 'Строка (Text)'],
                ['value' => 'number', 'label' => 'Число (Number)'],
                ['value' => 'boolean', 'label' => 'Логический (Checkbox)'],
                ['value' => 'select', 'label' => 'Выбор из списка (Select)'],
            ],
            'categories' => Category::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    /**
     * Store a newly created attribute in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:attributes,slug',
            'type' => 'required|string|in:string,number,boolean,select',
            'unit' => 'nullable|string|max:50',
            'is_filterable' => 'boolean',
            'sort_order' => 'nullable|integer',
            'values' => 'required_if:type,select|array',
            'values.*.value' => 'required|string|max:255',
            'values.*.sort_order' => 'nullable|integer',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'exists:categories,id',
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug(\App\Helpers\SearchHelper::transliterate($validated['name']));
        }

        $attribute = Attribute::create($validated);

        if ($validated['type'] === 'select' && !empty($validated['values'])) {
            foreach ($validated['values'] as $index => $valueData) {
                $attribute->values()->create([
                    'value' => $valueData['value'],
                    'sort_order' => $valueData['sort_order'] ?? $index,
                ]);
            }
        }

        if (!empty($validated['category_ids'])) {
            $attribute->categories()->sync($validated['category_ids']);
        }

        return redirect()
            ->route('admin.attributes.index')
            ->with('success', 'Атрибут успешно создан');
    }

    /**
     * Show the form for editing the specified attribute.
     */
    public function edit(Attribute $attribute): Response
    {
        $attribute->load(['values', 'categories:id,name']);

        return Inertia::render('Admin/Pages/Attributes/Edit', [
            'attribute' => $attribute,
            'types' => [
                ['value' => 'string', 'label' => 'Строка (Text)'],
                ['value' => 'number', 'label' => 'Число (Number)'],
                ['value' => 'boolean', 'label' => 'Логический (Checkbox)'],
                ['value' => 'select', 'label' => 'Выбор из списка (Select)'],
            ],
            'categories' => Category::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    /**
     * Update the specified attribute in storage.
     */
    public function update(Request $request, Attribute $attribute): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:attributes,slug,' . $attribute->id,
            'type' => 'required|string|in:string,number,boolean,select',
            'unit' => 'nullable|string|max:50',
            'is_filterable' => 'boolean',
            'sort_order' => 'nullable|integer',
            'values' => 'required_if:type,select|array',
            'values.*.id' => 'nullable|exists:attribute_values,id',
            'values.*.value' => 'required|string|max:255',
            'values.*.sort_order' => 'nullable|integer',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'exists:categories,id',
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug(\App\Helpers\SearchHelper::transliterate($validated['name']));
        }

        $attribute->update($validated);

        if ($validated['type'] === 'select' && isset($validated['values'])) {
            $keepIds = collect($validated['values'])->pluck('id')->filter()->toArray();
            $attribute->values()->whereNotIn('id', $keepIds)->delete();

            foreach ($validated['values'] as $index => $valueData) {
                if (isset($valueData['id'])) {
                    $attribute->values()->find($valueData['id'])->update([
                        'value' => $valueData['value'],
                        'sort_order' => $valueData['sort_order'] ?? $index,
                    ]);
                } else {
                    $attribute->values()->create([
                        'value' => $valueData['value'],
                        'sort_order' => $valueData['sort_order'] ?? $index,
                    ]);
                }
            }
        } elseif ($validated['type'] !== 'select') {
            $attribute->values()->delete();
        }

        $attribute->categories()->sync($validated['category_ids'] ?? []);

        return redirect()
            ->route('admin.attributes.index')
            ->with('success', 'Атрибут успешно обновлен');
    }

    /**
     * Remove the specified attribute from storage.
     */
    public function destroy(Attribute $attribute): RedirectResponse
    {
        $attribute->delete();

        return redirect()
            ->route('admin.attributes.index')
            ->with('success', 'Атрибут успешно удален');
    }
}
