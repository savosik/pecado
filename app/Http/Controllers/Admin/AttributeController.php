<?php

namespace App\Http\Controllers\Admin;

use App\Models\Attribute;
use App\Models\AttributeGroup;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\ProductAttributeValue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;

class AttributeController extends AdminController
{
    use RedirectsAfterSave;

    /**
     * Display a listing of the attributes.
     */
    public function index(Request $request): Response
    {
        $query = Attribute::query()->withCount('values')->with(['categories:id,name', 'attributeGroup:id,name']);

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
            'categoryTree' => Category::defaultOrder()->get()->toTree(),
            'attributeGroups' => AttributeGroup::orderBy('name')->get(['id', 'name']),
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
            'attribute_group_id' => 'nullable|exists:attribute_groups,id',
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

        return $this->redirectAfterSave($request, 'admin.attributes.index', 'admin.attributes.edit', $attribute, 'Атрибут успешно создан');
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
            'categoryTree' => Category::defaultOrder()->get()->toTree(),
            'attributeGroups' => AttributeGroup::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Update the specified attribute in storage.
     */
    public function update(Request $request, Attribute $attribute): RedirectResponse
    {
        $oldType = $attribute->type;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:attributes,slug,' . $attribute->id,
            'type' => 'required|string|in:string,number,boolean,select',
            'unit' => 'nullable|string|max:50',
            'is_filterable' => 'boolean',
            'sort_order' => 'nullable|integer',
            'values' => 'nullable|array',
            'values.*.id' => 'nullable|exists:attribute_values,id',
            'values.*.value' => 'required|string|max:255',
            'values.*.sort_order' => 'nullable|integer',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'exists:categories,id',
            'attribute_group_id' => 'nullable|exists:attribute_groups,id',
            '_convert_to_select' => 'nullable|boolean',
            '_convert_to_boolean' => 'nullable|boolean',
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug(\App\Helpers\SearchHelper::transliterate($validated['name']));
        }

        $attribute->update($validated);

        $convertToSelect = $oldType !== 'select'
            && $validated['type'] === 'select'
            && !empty($validated['_convert_to_select']);

        $convertToBoolean = $oldType !== 'boolean'
            && $validated['type'] === 'boolean'
            && !empty($validated['_convert_to_boolean']);

        if ($convertToSelect) {
            // Автоматическая конвертация: собираем значения из товаров
            $this->convertToSelect($attribute);
        } elseif ($convertToBoolean) {
            // Конвертация в булев тип
            $this->convertToBoolean($attribute);
        } elseif ($validated['type'] === 'select' && isset($validated['values'])) {
            // Обычное редактирование значений справочника
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

        return $this->redirectAfterSave($request, 'admin.attributes.index', 'admin.attributes.edit', $attribute, 'Атрибут успешно обновлен');
    }

    /**
     * Remove the specified attribute from storage.
     */
    public function destroy(Attribute $attribute): RedirectResponse
    {
        $attribute->delete();

        return redirect()->route('admin.attributes.index')->with('success', 'Атрибут успешно удален');
    }

    /**
     * Конвертировать атрибут в справочник: собрать уникальные значения из товаров
     * и создать записи AttributeValue, затем привязать product_attribute_values.
     */
    private function convertToSelect(Attribute $attribute): void
    {
        // 1. Собираем уникальные текстовые значения из product_attribute_values
        $uniqueValues = ProductAttributeValue::where('attribute_id', $attribute->id)
            ->whereNotNull('text_value')
            ->where('text_value', '!=', '')
            ->select('text_value')
            ->distinct()
            ->orderBy('text_value')
            ->pluck('text_value');

        if ($uniqueValues->isEmpty()) {
            return;
        }

        // 2. Создаём записи AttributeValue для каждого уникального значения
        $valueMap = []; // text_value => attribute_value_id
        foreach ($uniqueValues as $index => $textValue) {
            $attrValue = AttributeValue::firstOrCreate(
                [
                    'attribute_id' => $attribute->id,
                    'value' => $textValue,
                ],
                [
                    'sort_order' => $index,
                ]
            );
            $valueMap[$textValue] = $attrValue->id;
        }

        // 3. Обновляем product_attribute_values: привязываем attribute_value_id
        foreach ($valueMap as $textValue => $attributeValueId) {
            ProductAttributeValue::where('attribute_id', $attribute->id)
                ->where('text_value', $textValue)
                ->update([
                    'attribute_value_id' => $attributeValueId,
                    'text_value' => null,
                ]);
        }
    }

    /**
     * Конвертировать атрибут в булев тип: преобразовать текстовые значения
     * товаров в boolean_value.
     */
    private function convertToBoolean(Attribute $attribute): void
    {
        $trueValues = ['да', 'yes', 'true', '1', 'on'];

        // Обновляем записи с "истинными" значениями
        ProductAttributeValue::where('attribute_id', $attribute->id)
            ->whereNotNull('text_value')
            ->get()
            ->each(function ($pav) use ($trueValues) {
                $pav->update([
                    'boolean_value' => in_array(mb_strtolower(trim($pav->text_value)), $trueValues),
                    'text_value' => null,
                    'number_value' => null,
                    'attribute_value_id' => null,
                ]);
            });

        // Обновляем записи с числовыми значениями
        ProductAttributeValue::where('attribute_id', $attribute->id)
            ->whereNotNull('number_value')
            ->update([
                'boolean_value' => true,
                'number_value' => null,
            ]);

        // Удаляем значения справочника, если были
        $attribute->values()->delete();
    }
}
