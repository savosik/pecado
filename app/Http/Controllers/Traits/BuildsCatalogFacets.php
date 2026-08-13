<?php

namespace App\Http\Controllers\Traits;

use App\Services\CurrencyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Сборка фасетов и ценовых интервалов поверх произвольного базового запроса.
 *
 * Используют каталог (`CatalogApiController`) и поиск (`SearchApiController`) —
 * отличается только то, из чего строится базовый запрос.
 *
 * Требует свойство `$facetService` (App\Services\Product\CatalogFacetService).
 */
trait BuildsCatalogFacets
{
    /**
     * Фасеты брендов, категорий и атрибутов.
     *
     * Каждая группа считается по запросу БЕЗ собственного фильтра, иначе
     * счётчики схлопнулись бы до одного выбранного значения.
     *
     * @param  array<string, mixed>  $validated
     * @param  callable(array<string, mixed>): Builder  $build
     * @return array<string, mixed>
     */
    protected function assembleFacets(array $validated, callable $build): array
    {
        $withoutBrands = $validated;
        unset($withoutBrands['brand_ids']);

        $withoutCategories = $validated;
        unset($withoutCategories['category_ids']);

        $selectedBrandIds = array_map(
            'intval',
            $validated['brand_ids'] ?? [],
        );
        $selectedCategoryIds = array_map(
            'intval',
            $validated['category_ids'] ?? [],
        );
        if (! empty($validated['category_id']) && ! in_array((int) $validated['category_id'], $selectedCategoryIds)) {
            $selectedCategoryIds[] = (int) $validated['category_id'];
        }

        $selectedAttributeValueIds = array_map(
            'intval',
            $validated['attribute_value_ids'] ?? [],
        );
        $withoutAttributes = $validated;
        unset($withoutAttributes['attribute_value_ids']);
        unset($withoutAttributes['attribute_inline_filters']);

        return [
            'brands' => $this->facetService->getBrandFacets($build($withoutBrands), $selectedBrandIds),
            'categories' => $this->facetService->getCategoryFacets($build($withoutCategories), $selectedCategoryIds),
            'attributes' => $this->facetService->getAttributeFacets(
                $build($withoutAttributes),
                $selectedAttributeValueIds,
            ),
        ];
    }

    /**
     * Ценовые интервалы по базовому запросу, сконвертированные в валюту пользователя.
     *
     * @return array<string, mixed>
     */
    protected function buildPriceIntervals(Builder $query): array
    {
        $intervals = $this->facetService->getPriceIntervals($query);

        $user = Auth::user();
        if ($user && $user->region?->currency && ! $user->region->currency->is_base) {
            $currencyService = app(CurrencyService::class);
            $currency = $user->region->currency;

            $intervals['min'] = $currencyService->convertFromBase($intervals['min'], $currency);
            $intervals['max'] = $currencyService->convertFromBase($intervals['max'], $currency);

            foreach ($intervals['buckets'] as &$bucket) {
                $bucket['from'] = $currencyService->convertFromBase($bucket['from'], $currency);
                $bucket['to'] = $currencyService->convertFromBase($bucket['to'], $currency);
            }
            unset($bucket);
        }

        return $intervals;
    }
}
