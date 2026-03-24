<?php

namespace App\Http\Requests\User;

use App\Enums\CatalogSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Валидация параметров фильтрации каталога товаров.
 *
 * Поддерживает compact URL параметры (fv, b, c, cl, s, v, pp, p),
 * которые разворачиваются в полные имена в prepareForValidation().
 */
class ProductFilterRequest extends FormRequest
{
    /**
     * Маппинг compact → полное имя параметра.
     */
    private const COMPACT_MAP = [
        'fv' => 'attribute_value_ids',
        'fi' => 'attribute_inline_filters',
        'b' => 'brand_ids',
        'c' => 'category_ids',
        'cl' => 'collection_ids',
        's' => 'sort',
        'v' => 'view',
        'pp' => 'per_page',
        'p' => 'page',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Развернуть compact URL параметры в полные имена.
     *
     * Если и compact, и полное имя поданы — приоритет у полного.
     */
    protected function prepareForValidation(): void
    {
        $expanded = [];

        foreach (self::COMPACT_MAP as $short => $full) {
            if ($this->has($short) && ! $this->has($full)) {
                $expanded[$full] = $this->input($short);
            }
        }

        if (! empty($expanded)) {
            $this->merge($expanded);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => 'nullable|string|max:200',
            'category_id' => 'nullable|integer|exists:categories,id',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'integer|exists:categories,id',
            'include_descendants' => 'nullable|boolean',
            'brand_ids' => 'nullable|array',
            'brand_ids.*' => 'integer|exists:brands,id',
            'collection_ids' => 'nullable|array',
            'collection_ids.*' => 'integer|exists:product_selections,id',
            'price_min' => 'nullable|numeric|min:0',
            'price_max' => 'nullable|numeric|min:0',
            'in_stock' => 'nullable|boolean',
            'in_stock_mode' => 'nullable|in:instock,preorder,notavailable',
            'in_sale' => 'nullable|in:0,1',
            'in_favourites' => 'nullable|boolean',
            'is_new' => 'nullable|boolean',
            'is_bestseller' => 'nullable|boolean',
            'attribute_value_ids' => 'nullable|array',
            'attribute_value_ids.*' => 'integer|exists:attribute_values,id',
            'attribute_inline_filters' => 'nullable|array',
            'attribute_inline_filters.*' => 'nullable|array',
            'attribute_inline_filters.*.*' => 'string|max:100',
            'attribute_any' => 'nullable|in:0,1',
            'sort' => ['nullable', Rule::enum(CatalogSort::class)],
            'view' => 'nullable|in:grid,list',
            'per_page' => 'nullable|in:10,20,40,60,100',
            'page' => 'nullable|integer|min:1',
        ];
    }

    /**
     * Русские сообщения об ошибках валидации.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.max' => 'Поисковый запрос не должен превышать :max символов.',
            'category_id.exists' => 'Выбранная категория не найдена.',
            'category_ids.*.exists' => 'Одна из выбранных категорий не найдена.',
            'brand_ids.*.exists' => 'Один из выбранных брендов не найден.',
            'collection_ids.*.exists' => 'Одна из выбранных подборок не найдена.',
            'price_min.min' => 'Минимальная цена не может быть отрицательной.',
            'price_max.min' => 'Максимальная цена не может быть отрицательной.',
            'in_stock_mode.in' => 'Некорректный режим наличия.',
            'in_sale.in' => 'Параметр скидки должен быть 0 или 1.',
            'attribute_value_ids.*.exists' => 'Одно из значений атрибутов не найдено.',
            'attribute_any.in' => 'Параметр логики атрибутов должен быть 0 или 1.',
            'sort.Illuminate\Validation\Rules\Enum' => 'Некорректный параметр сортировки.',
            'view.in' => 'Некорректный режим отображения.',
            'per_page.in' => 'Допустимые значения: 10, 20, 40, 60, 100.',
            'page.min' => 'Номер страницы должен быть не менее 1.',
        ];
    }

    /**
     * Есть ли активные пользовательские фильтры (кроме sort/view/page/per_page).
     */
    public function filtersApplied(): bool
    {
        $filterKeys = [
            'q', 'category_id', 'category_ids', 'brand_ids',
            'collection_ids', 'price_min', 'price_max', 'in_stock',
            'in_stock_mode', 'in_sale', 'in_favourites', 'is_new', 'is_bestseller',
            'attribute_value_ids', 'attribute_inline_filters', 'attribute_any',
        ];

        foreach ($filterKeys as $key) {
            $value = data_get($this->validated(), $key);

            if ($value !== null && $value !== '' && $value !== []) {
                return true;
            }
        }

        return false;
    }
}
