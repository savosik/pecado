import { Box, Flex, Text } from '@chakra-ui/react';
import { LuX } from 'react-icons/lu';

const STOCK_LABELS = {
    instock: 'В наличии',
    preorder: 'Предзаказ',
    notavailable: 'Нет в наличии',
};

/**
 * SelectedFilters — горизонтальная панель выбранных фильтров (чипы).
 *
 * Каждый чип = текст фильтра + кнопка × для удаления.
 * Кнопка «Сбросить всё» — очищает фильтры, не трогает sort/view/per_page.
 *
 * @param {{
 *   filters: object,
 *   facets: { brands: Array, categories: Array, attributes: Array } | null,
 *   onRemoveFilter: (key: string, value?: any) => void,
 *   onResetAll: () => void,
 * }} props
 */
export default function SelectedFilters({ filters, facets, onRemoveFilter, onResetAll }) {
    const chips = buildChips(filters, facets);

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
                    bg="pink.50"
                    _dark={{ bg: 'pink.900/30', color: 'pink.200' }}
                    color="pink.700"
                    borderRadius="full"
                    px="3"
                    py="1"
                    fontSize="xs"
                    fontWeight="500"
                    transition="all 0.15s"
                    _hover={{ bg: 'pink.100', _dark: { bg: 'pink.900/50' } }}
                >
                    <Text>{chip.label}</Text>
                    <Box
                        as="button"
                        display="flex"
                        alignItems="center"
                        cursor="pointer"
                        color="pink.400"
                        _hover={{ color: 'pink.600' }}
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
                _hover={{ color: 'pink.500' }}
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
 */
function buildChips(filters, facets) {
    const chips = [];

    // Поиск
    if (filters.q) {
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
            label: `${Number(filters.price_min).toLocaleString('ru-RU')} – ${Number(filters.price_max).toLocaleString('ru-RU')} ₽`,
        });
    } else if (hasMin) {
        chips.push({
            key: 'price_min',
            filterKey: 'price_min',
            label: `от ${Number(filters.price_min).toLocaleString('ru-RU')} ₽`,
        });
    } else if (hasMax) {
        chips.push({
            key: 'price_max',
            filterKey: 'price_max',
            label: `до ${Number(filters.price_max).toLocaleString('ru-RU')} ₽`,
        });
    }

    // Наличие
    if (filters.in_stock_mode && STOCK_LABELS[filters.in_stock_mode]) {
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

    // Бренды
    if (Array.isArray(filters.brand_ids) && filters.brand_ids.length > 0) {
        filters.brand_ids.forEach((brandId) => {
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

    // Категории
    if (Array.isArray(filters.category_ids) && filters.category_ids.length > 0) {
        filters.category_ids.forEach((catId) => {
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

    // Атрибуты
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

    return chips;
}
