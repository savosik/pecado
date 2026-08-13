<?php

namespace App\Http\Requests\User;

use App\Enums\CatalogSort;
use Illuminate\Validation\Rule;

/**
 * Валидация параметров поиска товаров (`/api/search/products*`).
 *
 * Тот же набор фильтров, что и в каталоге, с двумя отличиями:
 * запрос `q` обязателен, а к сортировкам добавляется `relevance`
 * (порядок Meilisearch) — она же значение по умолчанию.
 */
class SearchFilterRequest extends ProductFilterRequest
{
    /** Сортировка «по релевантности» — порядок, в котором ответил Meilisearch. */
    public const SORT_RELEVANCE = 'relevance';

    /**
     * Допустимые значения сортировки: релевантность + сортировки каталога.
     *
     * @return array<int, string>
     */
    public static function sortValues(): array
    {
        return array_merge(
            [self::SORT_RELEVANCE],
            array_column(CatalogSort::cases(), 'value'),
        );
    }

    /**
     * Опции сортировки для фронтенда.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function sortOptions(): array
    {
        return array_merge(
            [['value' => self::SORT_RELEVANCE, 'label' => 'По релевантности']],
            CatalogSort::options(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['q'] = 'required|string|min:2|max:200';
        $rules['sort'] = ['nullable', Rule::in(self::sortValues())];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'q.required' => 'Введите поисковый запрос.',
            'q.min' => 'Минимум 2 символа для поиска.',
            'sort.in' => 'Некорректный параметр сортировки.',
        ]);
    }
}
