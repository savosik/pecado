import { Box, Flex, Text } from '@chakra-ui/react';
import { LuX } from 'react-icons/lu';

const STOCK_LABELS = {
    instock: 'В наличии',
    available: 'В наличии или предзаказ',
    preorder: 'Предзаказ',
    notavailable: 'Нет в наличии',
    defect: 'Некондиция',
};

/**
 * SelectedFilters — горизонтальная панель выбранных фильтров (чипы).
 *
 * Каждый чип = текст фильтра + кнопка × для удаления.
 * Кнопка «Сбросить всё» — очищает фильтры, не трогает sort/view/per_page.
 *
 * lockedFilters — фильтры, заданные контекстом страницы (бренд, категория, подборка).
 * Они не показываются как чипы и не участвуют в «Сбросить всё».
 *
 * @param {{
 *   filters: object,
 *   facets: { brands: Array, categories: Array, attributes: Array } | null,
 *   lockedFilters?: object,
 *   onRemoveFilter: (key: string, value?: any) => void,
 *   onResetAll: () => void,
 * }} props
 */
export default function SelectedFilters({ filters, facets, lockedFilters, currencySymbol = '₽', onRemoveFilter, onResetAll }) {
    const chips = buildChips(filters, facets, lockedFilters, currencySymbol);

    if (chips.length === 0) return null;

    return (
        <Flex
            gap="2"
            flexWrap="wrap"
            align="center"
            mb="4"
        >
            {chips.map((chip) => (
                <Flex
                    key={chip.key}
                    align="center"
                    gap="1"
                    bg="pecado.50"
                    _dark={{ bg: 'pecado.900/30', color: 'pecado.200' }}
                    color="pecado.700"
                    borderRadius="full"
                    px="3"
                    py="1"
                    fontSize="xs"
                    fontWeight="500"
                    transition="all 0.15s"
                    _hover={{ bg: 'pecado.100', _dark: { bg: 'pecado.900/50' } }}
                >
                    <Text>{chip.label}</Text>
                    <Box
                        as="button"
                        display="flex"
                        alignItems="center"
                        cursor="pointer"
                        color="pecado.400"
                        _hover={{ color: 'pecado.600' }}
                        onClick={() => onRemoveFilter(chip.filterKey, chip.value)}
                        ml="0.5"
                        type="button"
                    >
                        <LuX size={12} />
                    </Box>
                </Flex>
            ))}

            <Text
                as="button"
                fontSize="xs"
                color="gray.500"
                _hover={{ color: 'pecado.500' }}
                cursor="pointer"
                ml="1"
                onClick={onResetAll}
            >
                Сбросить всё
            </Text>
        </Flex>
    );
}

/**
 * Построить массив чипов из текущих фильтров.
 * lockedFilters — фильтры, заданные контекстом страницы, которые не показываются как чипы.
 */
function buildChips(filters, facets, lockedFilters = {}, currencySymbol = '₽') {
    const chips = [];

    // Определяем «заблокированные» значения для массивных фильтров
    const lockedBrandIds = new Set((lockedFilters.brand_ids || []).map(Number));
    const lockedCategoryIds = new Set((lockedFilters.category_ids || []).map(Number));
    // Бэкенд byCategory передаёт category_id (singular) — учитываем и его
    if (lockedFilters.category_id) {
        lockedCategoryIds.add(Number(lockedFilters.category_id));
    }

    // Поиск. На странице /search запрос задан контекстом — чип не показываем,
    // снять его нельзя (иначе результатов просто не будет).
    if (filters.q && lockedFilters.q !== filters.q) {
        chips.push({
            key: 'q',
            filterKey: 'q',
            label: `Поиск: «${filters.q}»`,
        });
    }

    // Цена
    const hasMin = filters.price_min != null && filters.price_min !== '';
    const hasMax = filters.price_max != null && filters.price_max !== '';
    if (hasMin && hasMax) {
        chips.push({
            key: 'price_range',
            filterKey: 'price_range',
            label: `${Number(filters.price_min).toLocaleString('ru-RU')} – ${Number(filters.price_max).toLocaleString('ru-RU')} ${currencySymbol}`,
        });
    } else if (hasMin) {
        chips.push({
            key: 'price_min',
            filterKey: 'price_min',
            label: `от ${Number(filters.price_min).toLocaleString('ru-RU')} ${currencySymbol}`,
        });
    } else if (hasMax) {
        chips.push({
            key: 'price_max',
            filterKey: 'price_max',
            label: `до ${Number(filters.price_max).toLocaleString('ru-RU')} ${currencySymbol}`,
        });
    }

    // Наличие. Если режим задан контекстом страницы (напр. раздел «Уценка»
    // фиксирует defect) — чип не показываем, снять его нельзя.
    if (
        filters.in_stock_mode
        && STOCK_LABELS[filters.in_stock_mode]
        && lockedFilters.in_stock_mode !== filters.in_stock_mode
    ) {
        chips.push({
            key: 'in_stock_mode',
            filterKey: 'in_stock_mode',
            label: STOCK_LABELS[filters.in_stock_mode],
        });
    }

    // Скидка
    if (filters.in_sale === '1' || filters.in_sale === 1) {
        chips.push({
            key: 'in_sale',
            filterKey: 'in_sale',
            label: 'Со скидкой',
        });
    }

    // Участие в акции
    if (filters.in_promotion === '1' || filters.in_promotion === 1 || filters.in_promotion === true) {
        chips.push({
            key: 'in_promotion',
            filterKey: 'in_promotion',
            label: 'Участвует в акции',
        });
    }

    // Бренды (пропускаем заблокированные — заданные контекстом страницы)
    if (Array.isArray(filters.brand_ids) && filters.brand_ids.length > 0) {
        filters.brand_ids.forEach((brandId) => {
            if (lockedBrandIds.has(Number(brandId))) return;
            const brand = facets?.brands?.find((b) => b.id === Number(brandId));
            const name = brand ? brand.name : `#${brandId}`;
            chips.push({
                key: `brand_${brandId}`,
                filterKey: 'brand_ids',
                value: Number(brandId),
                label: `Бренд: ${name}`,
            });
        });
    }

    // Категории (пропускаем заблокированные)
    if (Array.isArray(filters.category_ids) && filters.category_ids.length > 0) {
        filters.category_ids.forEach((catId) => {
            if (lockedCategoryIds.has(Number(catId))) return;
            const cat = facets?.categories?.find((c) => c.id === Number(catId));
            const name = cat ? cat.name : `#${catId}`;
            chips.push({
                key: `category_${catId}`,
                filterKey: 'category_ids',
                value: Number(catId),
                label: `Категория: ${name}`,
            });
        });
    }

    // Атрибуты (select)
    if (Array.isArray(filters.attribute_value_ids) && filters.attribute_value_ids.length > 0) {
        filters.attribute_value_ids.forEach((valueId) => {
            let label = `#${valueId}`;
            if (facets?.attributes) {
                for (const attr of facets.attributes) {
                    const val = attr.values.find((v) => v.id === Number(valueId));
                    if (val) {
                        label = `${attr.name}: ${val.value}`;
                        break;
                    }
                }
            }
            chips.push({
                key: `attr_${valueId}`,
                filterKey: 'attribute_value_ids',
                value: Number(valueId),
                label,
            });
        });
    }

    // Атрибуты (inline — number/text/boolean)
    if (filters.attribute_inline_filters && typeof filters.attribute_inline_filters === 'object') {
        for (const [attrId, values] of Object.entries(filters.attribute_inline_filters)) {
            if (!Array.isArray(values)) continue;
            // Находим название атрибута из фасетов
            const attrFacet = facets?.attributes?.find((a) => a.id === Number(attrId));
            const attrName = attrFacet?.name || `Атрибут #${attrId}`;
            values.forEach((rawValue) => {
                chips.push({
                    key: `inline_${attrId}_${rawValue}`,
                    filterKey: 'attribute_inline_filters',
                    value: { attrId, rawValue },
                    label: `${attrName}: ${rawValue}`,
                });
            });
        }
    }

    return chips;
}

