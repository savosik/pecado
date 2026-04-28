import { useEffect, useMemo, useRef, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import {
    Box, Grid, GridItem, Heading, Text, Flex, HStack, Stack, Button, Card, Input, InputGroup, Badge,
} from '@chakra-ui/react';
import { LuFilter, LuHeart, LuLayoutGrid, LuListTree, LuSearch, LuX } from 'react-icons/lu';
import UserLayout from '../UserLayout';
import ProductCard from '@/components/product/ProductCard';
import Pagination from '@/components/common/Pagination';
import EmptyState from '@/components/common/EmptyState';
import { Field } from '@/components/ui/field';
import { Select } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import SelectedFilters from '@/components/cabinet/SelectedFilters';
import { useSearchHistory } from '@/hooks/useSearchHistory';
import usePagination from '@/hooks/usePagination';
import { pluralize } from '@/utils/pluralize';

/**
 * Страница «Избранное» — поиск, сортировка, фильтры и группировка по категории.
 */
export default function FavoritesIndex({ favorites, filters = {}, facets = {}, sortOptions = {}, availabilityOptions = {} }) {
    const pagination = usePagination(favorites);
    const { items, currentPage, lastPage, total, pageNumbers, onPageChange } = pagination;

    const [showFilters, setShowFilters] = useState(false);
    const [groupByCategory, setGroupByCategory] = useState(false);
    const [search, setSearch] = useState(filters?.search || '');
    const [localFilters, setLocalFilters] = useState({
        availability: filters?.availability || '',
        brand_ids: Array.isArray(filters?.brand_ids) ? filters.brand_ids.map(String) : [],
        category_ids: Array.isArray(filters?.category_ids) ? filters.category_ids.map(String) : [],
    });

    const navigateWithParams = (params) => {
        router.get('/favorites', {
            ...filters,
            ...params,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const { history: searchHistory, push: pushSearchHistory } = useSearchHistory('favorites');

    const lastSubmittedSearch = useRef(filters?.search || '');
    useEffect(() => {
        if (search === lastSubmittedSearch.current) return;
        const handle = setTimeout(() => {
            lastSubmittedSearch.current = search;
            pushSearchHistory(search);
            navigateWithParams({ search, page: 1 });
        }, 400);
        return () => clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const handleSearchSubmit = (e) => {
        e.preventDefault();
        lastSubmittedSearch.current = search;
        pushSearchHistory(search);
        navigateWithParams({ search, page: 1 });
    };

    const handleApplyFilters = () => {
        navigateWithParams({
            availability: localFilters.availability,
            brand_ids: localFilters.brand_ids,
            category_ids: localFilters.category_ids,
            page: 1,
        });
    };

    const handleResetFilters = () => {
        setLocalFilters({ availability: '', brand_ids: [], category_ids: [] });
        setSearch('');
        lastSubmittedSearch.current = '';
        navigateWithParams({
            search: '',
            availability: '',
            brand_ids: [],
            category_ids: [],
            sort: 'added_desc',
            page: 1,
        });
    };

    const handleSortChange = (sort) => {
        navigateWithParams({ sort, page: 1 });
    };

    const sortEntries = useMemo(() => Object.entries(sortOptions), [sortOptions]);
    const availabilityEntries = useMemo(() => Object.entries(availabilityOptions), [availabilityOptions]);

    const activeFiltersCount = useMemo(() => {
        let count = 0;
        if (filters?.availability) count++;
        if (Array.isArray(filters?.brand_ids) && filters.brand_ids.length > 0) count++;
        if (Array.isArray(filters?.category_ids) && filters.category_ids.length > 0) count++;
        return count;
    }, [filters]);

    const filterFields = useMemo(() => [
        { key: 'search', label: 'Поиск', formatter: (v) => `«${v}»` },
        {
            key: 'availability',
            label: 'Наличие',
            formatter: (v) => availabilityOptions?.[v] || v,
        },
        {
            key: 'brand_ids',
            label: 'Бренд',
            formatter: (v) => facets?.brands?.find((b) => String(b.id) === String(v))?.name || `#${v}`,
        },
        {
            key: 'category_ids',
            label: 'Категория',
            formatter: (v) => facets?.categories?.find((c) => String(c.id) === String(v))?.name || `#${v}`,
        },
    ], [facets, availabilityOptions]);

    const handleRemoveFilter = (key, value) => {
        const current = filters?.[key];
        let nextValue;
        if (Array.isArray(current)) {
            nextValue = current.filter((v) => String(v) !== String(value));
        } else {
            nextValue = '';
        }
        if (key === 'search') {
            setSearch('');
            lastSubmittedSearch.current = '';
        } else if (Object.prototype.hasOwnProperty.call(localFilters, key)) {
            setLocalFilters({
                ...localFilters,
                [key]: Array.isArray(nextValue) ? nextValue.map(String) : nextValue,
            });
        }
        navigateWithParams({ [key]: nextValue, page: 1 });
    };

    const groupedItems = useMemo(() => {
        if (!groupByCategory) return null;
        const map = new Map();
        for (const product of items) {
            const cat = product.category?.name || 'Без категории';
            if (!map.has(cat)) map.set(cat, []);
            map.get(cat).push(product);
        }
        return [...map.entries()].sort(([a], [b]) => a.localeCompare(b, 'ru'));
    }, [items, groupByCategory]);

    const currentSort = filters?.sort || 'added_desc';

    return (
        <UserLayout>
            <Head title="Избранное" />

            <Box spaceY="6">
                <Box>
                    <Flex align="baseline" gap="2">
                        <Heading as="h1" size={{ base: 'xl', md: '3xl' }} fontWeight="bold" color="fg">
                            Избранное
                        </Heading>
                        {total > 0 && (
                            <Text fontSize="sm" color="gray.500">
                                {total} {pluralize(total, 'товар', 'товара', 'товаров')}
                            </Text>
                        )}
                    </Flex>
                </Box>

                {/* Toolbar */}
                <Flex gap="2" align="center" wrap="wrap">
                    <Box as="form" onSubmit={handleSearchSubmit} flex="1" minW={{ base: '100%', md: '320px' }}>
                        <InputGroup startElement={<LuSearch size={16} />} flex="1">
                            <Input
                                placeholder="Поиск по товару, бренду, артикулу или штрихкоду…"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                size="sm"
                                list="favorites-search-history"
                            />
                        </InputGroup>
                        {searchHistory.length > 0 && (
                            <datalist id="favorites-search-history">
                                {searchHistory.map((q) => (
                                    <option key={q} value={q} />
                                ))}
                            </datalist>
                        )}
                    </Box>

                    <Button
                        onClick={() => setShowFilters((s) => !s)}
                        variant={showFilters || activeFiltersCount > 0 ? 'subtle' : 'outline'}
                        colorPalette={showFilters || activeFiltersCount > 0 ? 'pecado' : 'gray'}
                        size="sm"
                        flexShrink="0"
                        aria-expanded={showFilters}
                    >
                        <LuFilter size={16} />
                        <Box as="span" display={{ base: 'none', md: 'inline' }}>
                            {showFilters ? 'Скрыть фильтры' : 'Фильтры'}
                        </Box>
                        {activeFiltersCount > 0 && (
                            <Badge colorPalette="pecado" variant="solid" borderRadius="full" fontSize="2xs" px="1.5" minW="4">
                                {activeFiltersCount}
                            </Badge>
                        )}
                    </Button>

                    <Box minW={{ base: '100%', md: '220px' }}>
                        <Select.Root
                            value={[currentSort]}
                            onValueChange={(e) => handleSortChange(e.value[0] || 'added_desc')}
                            size="sm"
                        >
                            <Select.Trigger>
                                <Select.ValueText placeholder="Сортировка" />
                            </Select.Trigger>
                            <Select.Content>
                                {sortEntries.map(([value, label]) => (
                                    <Select.Item key={value} item={value}>
                                        {label}
                                    </Select.Item>
                                ))}
                            </Select.Content>
                        </Select.Root>
                    </Box>

                    <HStack gap="2" flexShrink="0">
                        <Switch
                            size="sm"
                            checked={groupByCategory}
                            onCheckedChange={(e) => setGroupByCategory(e.checked)}
                        >
                            <HStack gap="1">
                                <LuListTree size={14} />
                                <Text fontSize="sm" display={{ base: 'none', md: 'inline' }}>Группировать</Text>
                            </HStack>
                        </Switch>
                    </HStack>
                </Flex>

                {/* Расширенные фильтры */}
                {showFilters && (
                    <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} borderRadius="xl" border="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }} _dark={{ borderColor: 'gray.700' }}>
                        <Card.Body p="4">
                            <Stack gap="4">
                                <Flex gap="4" direction={{ base: 'column', md: 'row' }}>
                                    <Field label="Наличие" flex="1">
                                        <Select.Root
                                            value={localFilters.availability ? [localFilters.availability] : []}
                                            onValueChange={(e) => setLocalFilters({ ...localFilters, availability: e.value[0] || '' })}
                                            size="sm"
                                        >
                                            <Select.Trigger>
                                                <Select.ValueText placeholder="Любое" />
                                            </Select.Trigger>
                                            <Select.Content>
                                                <Select.Item item="">Любое</Select.Item>
                                                {availabilityEntries.map(([value, label]) => (
                                                    <Select.Item key={value} item={value}>
                                                        {label}
                                                    </Select.Item>
                                                ))}
                                            </Select.Content>
                                        </Select.Root>
                                    </Field>

                                    <Field label="Бренд" flex="1">
                                        <Select.Root
                                            multiple
                                            value={localFilters.brand_ids}
                                            onValueChange={(e) => setLocalFilters({ ...localFilters, brand_ids: e.value })}
                                            size="sm"
                                        >
                                            <Select.Trigger>
                                                <Select.ValueText placeholder="Все бренды">
                                                    {(values) => values.length === 0 ? 'Все бренды' : `Выбрано: ${values.length}`}
                                                </Select.ValueText>
                                            </Select.Trigger>
                                            <Select.Content>
                                                {(facets?.brands || []).map((b) => (
                                                    <Select.Item key={b.id} item={String(b.id)}>
                                                        {b.name}
                                                    </Select.Item>
                                                ))}
                                            </Select.Content>
                                        </Select.Root>
                                    </Field>

                                    <Field label="Категория" flex="1">
                                        <Select.Root
                                            multiple
                                            value={localFilters.category_ids}
                                            onValueChange={(e) => setLocalFilters({ ...localFilters, category_ids: e.value })}
                                            size="sm"
                                        >
                                            <Select.Trigger>
                                                <Select.ValueText placeholder="Все категории">
                                                    {(values) => values.length === 0 ? 'Все категории' : `Выбрано: ${values.length}`}
                                                </Select.ValueText>
                                            </Select.Trigger>
                                            <Select.Content>
                                                {(facets?.categories || []).map((c) => (
                                                    <Select.Item key={c.id} item={String(c.id)}>
                                                        {c.name}
                                                    </Select.Item>
                                                ))}
                                            </Select.Content>
                                        </Select.Root>
                                    </Field>
                                </Flex>

                                <Flex justify="end" gap="2">
                                    <Button size="sm" variant="ghost" onClick={handleResetFilters}>
                                        <LuX size={14} /> Сбросить всё
                                    </Button>
                                    <Button size="sm" colorPalette="pecado" onClick={handleApplyFilters}>
                                        Применить
                                    </Button>
                                </Flex>
                            </Stack>
                        </Card.Body>
                    </Card.Root>
                )}

                <SelectedFilters
                    filters={{ ...filters, search }}
                    fields={filterFields}
                    onRemove={handleRemoveFilter}
                    onResetAll={activeFiltersCount > 0 || search ? handleResetFilters : undefined}
                />

                {/* Товары или пустое состояние */}
                {items.length > 0 ? (
                    <>
                        {groupByCategory ? (
                            <Stack gap="6">
                                {groupedItems.map(([category, products]) => (
                                    <Box key={category}>
                                        <Flex align="baseline" gap="2" mb="3">
                                            <Heading as="h2" size="md" color="fg">
                                                <HStack gap="1.5">
                                                    <LuLayoutGrid size={16} />
                                                    <Text>{category}</Text>
                                                </HStack>
                                            </Heading>
                                            <Text fontSize="xs" color="gray.500">
                                                {products.length} {pluralize(products.length, 'товар', 'товара', 'товаров')}
                                            </Text>
                                        </Flex>
                                        <Grid
                                            templateColumns={{
                                                base: 'repeat(2, minmax(0, 1fr))',
                                                md: 'repeat(3, minmax(0, 1fr))',
                                                lg: 'repeat(4, minmax(0, 1fr))',
                                                xl: 'repeat(5, minmax(0, 1fr))',
                                            }}
                                            gap={{ base: '3', md: '4' }}
                                        >
                                            {products.map((product) => (
                                                <GridItem key={product.id} h="100%" overflow="hidden">
                                                    <ProductCard product={product} />
                                                </GridItem>
                                            ))}
                                        </Grid>
                                    </Box>
                                ))}
                            </Stack>
                        ) : (
                            <Grid
                                templateColumns={{
                                    base: 'repeat(2, minmax(0, 1fr))',
                                    md: 'repeat(3, minmax(0, 1fr))',
                                    lg: 'repeat(4, minmax(0, 1fr))',
                                    xl: 'repeat(5, minmax(0, 1fr))',
                                }}
                                gap={{ base: '3', md: '4' }}
                            >
                                {items.map((product) => (
                                    <GridItem key={product.id} h="100%" overflow="hidden">
                                        <ProductCard product={product} />
                                    </GridItem>
                                ))}
                            </Grid>
                        )}

                        <Pagination
                            currentPage={currentPage}
                            lastPage={lastPage}
                            pageNumbers={pageNumbers}
                            onPageChange={onPageChange}
                            total={total}
                        />
                    </>
                ) : (
                    <EmptyState
                        icon={LuHeart}
                        title={(filters?.search || activeFiltersCount > 0) ? 'Ничего не найдено' : 'Список избранного пуст'}
                        description={(filters?.search || activeFiltersCount > 0)
                            ? 'Попробуйте изменить запрос или сбросить фильтры'
                            : 'Добавляйте товары в избранное, нажимая на ❤ на карточке товара'}
                        action={(filters?.search || activeFiltersCount > 0)
                            ? { label: 'Сбросить фильтры', onClick: handleResetFilters }
                            : { label: 'Перейти в каталог', href: '/' }}
                    />
                )}
            </Box>
        </UserLayout>
    );
}
