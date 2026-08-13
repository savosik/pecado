import { useCallback } from 'react';
import { Flex } from '@chakra-ui/react';

import CollapsibleFilterCard from './filters/CollapsibleFilterCard';
import PriceFilter from './filters/PriceFilter';
import CategoryFilter from './filters/CategoryFilter';
import BrandFilter from './filters/BrandFilter';
import PromotionFilter from './filters/PromotionFilter';
import AttributeFilters from './filters/AttributeFilters';

const LS_ATTRIBUTES_OPEN_KEY = 'catalog_attributes_open';

/**
 * CatalogFilterSidebar — колонка фильтров каталога.
 *
 * Один и тот же набор используют каталог и страница поиска — и в десктопном
 * сайдбаре, и в мобильной шторке фильтров.
 *
 * @param {{
 *   filters: object,
 *   facets: { brands?: Array, categories?: Array, attributes?: Array } | null,
 *   priceData: object | null,
 *   currencySymbol?: string,
 *   isAuthenticated?: boolean,
 *   hideCategories?: boolean,
 *   hideBrands?: boolean,
 *   updateFilter: (key: string, value: any) => void,
 *   updateFilters: (updates: object) => void,
 * }} props
 *   hideCategories/hideBrands — фильтр задан контекстом страницы (листинг
 *   категории или бренда), менять его нельзя.
 */
export default function CatalogFilterSidebar({
    filters,
    facets,
    priceData,
    currencySymbol = '₽',
    isAuthenticated = false,
    hideCategories = false,
    hideBrands = false,
    updateFilter,
    updateFilters,
}) {
    const handlePriceChange = useCallback((min, max) => {
        updateFilters({ price_min: min || undefined, price_max: max || undefined });
    }, [updateFilters]);

    const handleCategoriesChange = useCallback((ids) => {
        updateFilter('category_ids', ids.length > 0 ? ids : undefined);
    }, [updateFilter]);

    const handleBrandsChange = useCallback((ids) => {
        updateFilter('brand_ids', ids.length > 0 ? ids : undefined);
    }, [updateFilter]);

    const handlePromotionChange = useCallback((checked) => {
        updateFilter('in_promotion', checked ? 1 : undefined);
    }, [updateFilter]);

    const handleAttributeValuesChange = useCallback((ids) => {
        updateFilter('attribute_value_ids', ids.length > 0 ? ids : undefined);
    }, [updateFilter]);

    const handleInlineAttributeChange = useCallback((inlineFilters) => {
        updateFilter('attribute_inline_filters', inlineFilters);
    }, [updateFilter]);

    return (
        <Flex direction="column" gap="2">
            {!hideCategories && facets?.categories && facets.categories.length > 0 && (
                <CollapsibleFilterCard title="Категории" storageKey="catalog_filter_categories_open" defaultOpen>
                    <CategoryFilter
                        categories={facets.categories}
                        selectedIds={filters.category_ids || []}
                        onChange={handleCategoriesChange}
                    />
                </CollapsibleFilterCard>
            )}

            {!hideBrands && facets?.brands && facets.brands.length > 0 && (
                <CollapsibleFilterCard title="Бренды" storageKey="catalog_filter_brands_open" defaultOpen>
                    <BrandFilter
                        brands={facets.brands}
                        selectedIds={filters.brand_ids || []}
                        onChange={handleBrandsChange}
                    />
                </CollapsibleFilterCard>
            )}

            {isAuthenticated && (
                <CollapsibleFilterCard title="Цена" storageKey="catalog_filter_price_open" defaultOpen>
                    <PriceFilter
                        priceMin={filters.price_min || ''}
                        priceMax={filters.price_max || ''}
                        priceData={priceData}
                        currencySymbol={currencySymbol}
                        onPriceChange={handlePriceChange}
                    />
                </CollapsibleFilterCard>
            )}

            <CollapsibleFilterCard title="Акции" storageKey="catalog_filter_promotion_open" defaultOpen>
                <PromotionFilter
                    value={Boolean(filters.in_promotion)}
                    onChange={handlePromotionChange}
                />
            </CollapsibleFilterCard>

            {facets?.attributes && facets.attributes.length > 0 && (
                <CollapsibleFilterCard title="Характеристики" storageKey={LS_ATTRIBUTES_OPEN_KEY}>
                    <AttributeFilters
                        attributes={facets.attributes}
                        selectedValueIds={filters.attribute_value_ids || []}
                        selectedInlineFilters={filters.attribute_inline_filters || {}}
                        onChange={handleAttributeValuesChange}
                        onInlineChange={handleInlineAttributeChange}
                    />
                </CollapsibleFilterCard>
            )}
        </Flex>
    );
}
