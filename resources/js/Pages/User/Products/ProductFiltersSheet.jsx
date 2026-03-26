import { Box, Button, CloseButton, Drawer, Flex, Portal } from '@chakra-ui/react';
import { LuSlidersHorizontal } from 'react-icons/lu';

/**
 * Подсчёт количества активных фильтров для badge.
 */
export function countActiveFilters(filters) {
    let count = 0;

    if (filters.q) count++;
    // Ценовой диапазон считаем как один фильтр
    const hasPrice = (filters.price_min != null && filters.price_min !== '')
        || (filters.price_max != null && filters.price_max !== '');
    if (hasPrice) count++;
    if (filters.in_stock_mode) count++;
    if (filters.in_sale === '1' || filters.in_sale === 1) count++;
    if (Array.isArray(filters.brand_ids)) count += filters.brand_ids.length;
    if (Array.isArray(filters.category_ids)) count += filters.category_ids.length;
    if (Array.isArray(filters.attribute_value_ids)) count += filters.attribute_value_ids.length;
    if (filters.attribute_inline_filters && typeof filters.attribute_inline_filters === 'object') {
        for (const values of Object.values(filters.attribute_inline_filters)) {
            if (Array.isArray(values)) count += values.length;
        }
    }

    return count;
}

/**
 * FilterBadge — маленький числовой бейдж для кнопки/заголовка фильтра.
 */
export function FilterBadge({ count, ...rest }) {
    if (!count || count <= 0) return null;
    return (
        <Box
            as="span"
            bg="pecado.500"
            color="white"
            fontSize="11px"
            fontWeight="600"
            borderRadius="full"
            minW="20px"
            h="20px"
            lineHeight="20px"
            textAlign="center"
            px="5px"
            {...rest}
        >
            {count}
        </Box>
    );
}

/**
 * ProductFiltersSheet — Drawer (offcanvas) с фильтрами для мобильной версии.
 *
 * @param {{
 *   open: boolean,
 *   onClose: () => void,
 *   children: React.ReactNode,
 *   totalProducts: number | null,
 *   activeCount: number,
 *   onResetAll: () => void,
 * }} props
 */
export default function ProductFiltersSheet({
    open,
    onClose,
    children,
    totalProducts = null,
    activeCount = 0,
    onResetAll,
}) {
    const handleReset = () => {
        onResetAll();
        onClose();
    };

    const showLabel = totalProducts != null
        ? `Показать ${totalProducts.toLocaleString('ru-RU')} ${pluralize(totalProducts)}`
        : 'Показать товары';

    return (
        <Drawer.Root open={open} onOpenChange={(e) => !e.open && onClose()} placement="start" size="sm">
            <Portal>
                <Drawer.Backdrop />
                <Drawer.Positioner>
                    <Drawer.Content>
                        {/* Header */}
                        <Drawer.Header
                            borderBottom="1px solid"
                            borderColor="gray.100" _dark={{ borderColor: "gray.700" }}
                            _dark={{ borderColor: 'gray.700' }}
                        >
                            <Drawer.Title fontSize="lg" fontWeight="700">
                                <Flex align="center" gap="2">
                                    <LuSlidersHorizontal size={18} />
                                    Фильтры
                                    <FilterBadge count={activeCount} />
                                </Flex>
                            </Drawer.Title>
                            <Drawer.CloseTrigger asChild>
                                <CloseButton size="sm" />
                            </Drawer.CloseTrigger>
                        </Drawer.Header>

                        {/* Body — содержимое фильтров */}
                        <Drawer.Body py="2" px="3">
                            {children}
                        </Drawer.Body>

                        {/* Footer — кнопки Сбросить / Показать */}
                        <Drawer.Footer
                            borderTop="1px solid"
                            borderColor="gray.100" _dark={{ borderColor: "gray.700" }}
                            _dark={{ borderColor: 'gray.700' }}
                            p="3"
                            gap="2"
                        >
                            <Button
                                variant="outline"
                                colorPalette="gray"
                                size="sm"
                                flex="1"
                                onClick={handleReset}
                                disabled={activeCount === 0}
                            >
                                Сбросить
                            </Button>
                            <Button
                                colorPalette="pecado"
                                size="sm"
                                flex="2"
                                onClick={onClose}
                            >
                                {showLabel}
                            </Button>
                        </Drawer.Footer>
                    </Drawer.Content>
                </Drawer.Positioner>
            </Portal>
        </Drawer.Root>
    );
}

/**
 * Простое склонение «товар/товара/товаров» для русского языка.
 */
function pluralize(n) {
    const abs = Math.abs(n) % 100;
    const lastDigit = abs % 10;
    if (abs > 10 && abs < 20) return 'товаров';
    if (lastDigit > 1 && lastDigit < 5) return 'товара';
    if (lastDigit === 1) return 'товар';
    return 'товаров';
}
