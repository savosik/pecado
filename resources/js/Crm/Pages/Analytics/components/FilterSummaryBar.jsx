import { Box, HStack, Text, Wrap, WrapItem, Badge } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { LuFilter, LuRotateCcw, LuDownload, LuX } from 'react-icons/lu';

const fmtDate = (s) => {
    if (!s) return null;
    const d = new Date(s);
    if (Number.isNaN(d.getTime())) return s;
    return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' });
};

const nameById = (opts, id) => {
    const found = (opts || []).find((o) => String(o.id) === String(id));
    return found?.name ?? null;
};

/**
 * Чип активного фильтра. Крестик (если передан onRemove) сбрасывает измерение,
 * не открывая шторку.
 */
function Chip({ children, onRemove }) {
    return (
        <Badge variant="subtle" colorPalette="gray" borderRadius="full" px={2} py={1}>
            <HStack gap={1}>
                <Text fontSize="xs" lineClamp={1} maxW="220px">{children}</Text>
                {onRemove && (
                    <Box
                        as="button"
                        type="button"
                        onClick={onRemove}
                        display="inline-flex"
                        color="fg.muted"
                        _hover={{ color: 'fg' }}
                        aria-label="Убрать фильтр"
                    >
                        <LuX size={12} />
                    </Box>
                )}
            </HStack>
        </Badge>
    );
}

/**
 * Тонкая липкая полоса-сводка над отчётом: показывает активные фильтры чипами
 * и открывает полный набор контролов в боковой шторке. Позволяет менять фильтры
 * без прокрутки к началу страницы.
 */
export default function FilterSummaryBar({
    filters,
    filterOptions,
    products = [],
    comparisonLabel = null,
    loading = false,
    onOpen,
    onReset,
    onExport,
    onClear,
    onProductsClear,
    onComparisonClear,
}) {
    const managerIds = filters.manager_ids || [];
    const companyIds = filters.company_ids || [];
    const brandIds = filters.brand_ids || [];
    const categoryIds = filters.category_ids || [];

    const periodChip = (!filters.date_from && !filters.date_to)
        ? 'Текущий месяц'
        : `${fmtDate(filters.date_from) || '…'} – ${fmtDate(filters.date_to) || '…'}`;

    const dimChip = (ids, opts, one, many) => {
        if (ids.length === 0) return null;
        if (ids.length === 1) return nameById(opts, ids[0]) || one;
        return `${many}: ${ids.length}`;
    };

    const managerText = dimChip(managerIds, filterOptions?.managers, 'Менеджер', 'Менеджеры');
    const companyText = dimChip(companyIds, filterOptions?.companies, 'Контрагент', 'Контрагенты');
    const brandText = dimChip(brandIds, filterOptions?.brands, 'Бренд', 'Бренды');
    const categoryText = categoryIds.length > 0 ? `Категории: ${categoryIds.length}` : null;
    const productText = products.length === 1
        ? (products[0]?.name || 'Товар')
        : (products.length > 1 ? `Товары: ${products.length}` : null);

    const activeCount = [managerText, companyText, brandText, categoryText, productText, comparisonLabel]
        .filter(Boolean).length;

    return (
        <Box
            position="sticky"
            top={{ base: '56px', md: '60px' }}
            zIndex={4}
            bg="bg.panel"
            borderRadius="xl"
            borderWidth="1px"
            borderColor="border"
            px={3}
            py={2}
            boxShadow="xs"
        >
            <HStack justify="space-between" gap={3} wrap="wrap">
                <HStack gap={2} flex="1" minW="0" wrap="wrap">
                    <HStack gap={1} color="fg.muted" flexShrink={0}>
                        <LuFilter size={15} />
                        <Text fontSize="sm" fontWeight="600">Фильтры</Text>
                    </HStack>
                    <Wrap gap={1.5}>
                        <WrapItem><Chip>{periodChip}</Chip></WrapItem>
                        {managerText && (
                            <WrapItem><Chip onRemove={() => onClear?.({ manager_ids: [] })}>{managerText}</Chip></WrapItem>
                        )}
                        {companyText && (
                            <WrapItem><Chip onRemove={() => onClear?.({ company_ids: [] })}>{companyText}</Chip></WrapItem>
                        )}
                        {brandText && (
                            <WrapItem><Chip onRemove={() => onClear?.({ brand_ids: [] })}>{brandText}</Chip></WrapItem>
                        )}
                        {categoryText && (
                            <WrapItem><Chip onRemove={() => onClear?.({ category_ids: [] })}>{categoryText}</Chip></WrapItem>
                        )}
                        {productText && (
                            <WrapItem><Chip onRemove={onProductsClear}>{productText}</Chip></WrapItem>
                        )}
                        {comparisonLabel && (
                            <WrapItem><Chip onRemove={onComparisonClear}>⇄ {comparisonLabel}</Chip></WrapItem>
                        )}
                    </Wrap>
                </HStack>

                <HStack gap={2} flexShrink={0}>
                    {activeCount > 0 && (
                        <Button size="xs" variant="ghost" onClick={onReset} disabled={loading}>
                            <LuRotateCcw /> Сбросить
                        </Button>
                    )}
                    <Button size="xs" variant="outline" onClick={onExport} disabled={loading}>
                        <LuDownload /> XLSX
                    </Button>
                    <Button size="xs" variant="solid" colorPalette="red" onClick={onOpen}>
                        <LuFilter /> Фильтры{activeCount > 0 ? ` · ${activeCount}` : ''}
                    </Button>
                </HStack>
            </HStack>
        </Box>
    );
}
