<?php

namespace App\Services\Product;

use App\Models\Product;
use App\Models\Region;
use App\Services\CurrencyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Сборка базового запроса каталога по параметрам фильтрации.
 *
 * Один и тот же набор фильтров применяют каталог (`/api/catalog/products*`)
 * и поиск (`/api/search/products*`) — разница только в источнике товаров:
 * каталог берёт всю базу, поиск ограничивает выборку набором id из Meilisearch.
 */
class CatalogQueryBuilder
{
    /**
     * Построить запрос с применением всех фильтров из validated-параметров.
     *
     * @param  array<string, mixed>  $validated
     * @param  bool  $hideUnavailableByDefault  Скрывать ли товары без остатков, когда
     *                                          фильтр наличия не задан. В каталоге — да
     *                                          (витрина), в поиске — нет: искали конкретный
     *                                          товар и должны его найти, даже если его нет.
     */
    public function build(array $validated, bool $hideUnavailableByDefault = true): Builder
    {
        $query = Product::query();
        $user = Auth::user();
        // Гость трактуется так, будто у него уже назначен регион по умолчанию —
        // это обеспечивает консистентность счётчиков фильтров и списка товаров.
        $regionId = $user !== null ? $user->region_id : null;
        $regionId = $regionId ?? Region::defaultId();

        // Исключаем товары из неактивных категорий
        $query->where(function ($q) {
            $q->whereNull('category_id')
                ->orWhereHas('category', fn ($cq) => $cq->where('is_active', true));
        });

        // Поиск
        if (! empty($validated['q'])) {
            $query->search($validated['q']);
        }

        // Категория (одиночная, из маршрута)
        if (! empty($validated['category_id'])) {
            $descendants = ($validated['include_descendants'] ?? true);
            $query->inCategory((int) $validated['category_id'], (bool) $descendants);
        }

        // Категории (множественные)
        if (! empty($validated['category_ids'])) {
            $descendants = ($validated['include_descendants'] ?? true);
            $query->inCategories(
                array_map('intval', $validated['category_ids']),
                (bool) $descendants,
            );
        }

        // Бренды
        if (! empty($validated['brand_ids'])) {
            $query->inBrands(array_map('intval', $validated['brand_ids']));
        }

        // Подборки (коллекции)
        if (! empty($validated['collection_ids'])) {
            $query->inCollections(array_map('intval', $validated['collection_ids']));
        }

        // Цена (конвертируем из валюты пользователя в базовую для запроса к base_price)
        $priceMin = $validated['price_min'] ?? null;
        $priceMax = $validated['price_max'] ?? null;
        if ($priceMin !== null || $priceMax !== null) {
            $minVal = $priceMin !== null ? (float) $priceMin : null;
            $maxVal = $priceMax !== null ? (float) $priceMax : null;

            // Если у пользователя не базовая валюта — конвертируем обратно в базовую
            if ($user && $user->region?->currency && ! $user->region->currency->is_base) {
                $currencyService = app(CurrencyService::class);
                if ($minVal !== null) {
                    $minVal = $currencyService->convertToBase($minVal, $user->region->currency);
                }
                if ($maxVal !== null) {
                    $maxVal = $currencyService->convertToBase($maxVal, $user->region->currency);
                }
            }

            $query->byPrice($minVal, $maxVal);
        }

        // Наличие
        $stockMode = $validated['in_stock_mode'] ?? null;
        if ($stockMode === 'defect') {
            // «Некондиция»: товары с партиями уценки в продаже. Складские
            // остатки региона не учитываем — у уценки свой склад некондиции.
            $query->withSellableDefects();
        } elseif ($stockMode === 'available') {
            // «В наличии или предзаказ» — явный выбор пользователя
            $query->available($regionId);
        } elseif (! empty($stockMode)) {
            $query->inStock($stockMode, $regionId);
        } elseif (! empty($validated['in_stock'])) {
            $query->inStock('instock', $regionId);
        } elseif ($hideUnavailableByDefault) {
            // По умолчанию скрываем товары «нет в наличии» —
            // показываем только «в наличии» и «предзаказ»
            $query->available($regionId);
        }

        // Скидка (in_sale=1 → только со скидкой; in_sale=0 или отсутствует → без фильтра)
        if (! empty($validated['in_sale'])) {
            $query->inSale(true);
        }

        // Избранное
        if (! empty($validated['in_favourites']) && $user) {
            $query->inFavourites($user->id);
        }

        // Новинки
        if (! empty($validated['is_new'])) {
            $query->where('is_new', true);
        }

        // Бестселлеры
        if (! empty($validated['is_bestseller'])) {
            $query->where('is_bestseller', true);
        }

        // Участие в акции — тот же источник, что и бейдж «Акция» в карточке
        if (! empty($validated['in_promotion'])) {
            $query->inPromotion($regionId);
        }

        // Атрибуты (select — через attribute_values)
        if (! empty($validated['attribute_value_ids'])) {
            $any = (bool) ($validated['attribute_any'] ?? false);
            $query->byAttributes(
                array_map('intval', $validated['attribute_value_ids']),
                $any,
            );
        }

        // Атрибуты (inline — number/text/boolean)
        if (! empty($validated['attribute_inline_filters'])) {
            $query->byInlineAttributes($validated['attribute_inline_filters']);
        }

        return $query;
    }
}
