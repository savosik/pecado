import { useEffect, useMemo, useRef, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import {
    Box, Heading, Text, Flex, HStack, Stack, Button, Card, Input, InputGroup, Badge,
    createListCollection,
} from '@chakra-ui/react';
import { LuFilter, LuGrid2X2, LuHeart, LuLayoutGrid, LuLayoutList, LuListTree, LuSearch, LuX } from 'react-icons/lu';
import UserLayout from '../UserLayout';
import ProductGrid from '@/Pages/User/Products/ProductGrid';
import Pagination from '@/components/common/Pagination';
import { getResponsiveDefaultView } from '@/utils/compactFilters';
import EmptyState from '@/components/common/EmptyState';
import { Field } from '@/components/ui/field';
import { Select } from '@/components/ui/select';
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
    // На мобиле — list view по умолчанию, на md+ — grid; пользователь может переключить.
    const [view, setView] = useState(() => getResponsiveDefaultView());
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

    // Chakra v3 Select требует collection — без неё опции не выбираются.
    const sortCollection = useMemo(
        () => createListCollection({ items: sortEntries.map(([value, label]) => ({ label, value })) }),
        [sortEntries]
    );
    const availabilityCollection = useMemo(
        () => createListCollection({
            items: [{ label: 'Любое', value: '' }, ...availabilityEntries.map(([value, label]) => ({ label, value }))],
        }),
        [availabilityEntries]
    );
    const brandCollection = useMemo(
        () => createListCollection({
            items: (facets?.brands || []).map((b) => ({ label: b.name, value: String(b.id) })),
        }),
        [facets?.brands]
    );
    const categoryCollection = useMemo(
        () => createListCollection({
            items: (facets?.categories || []).map((c) => ({ label: c.name, value: String(c.id) })),
        }),
        [facets?.categories]
    );

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
        <UserLayout fluid>
            <Head title="Избранное" />

            <Box spaceY="6">
                <Box px={{ base: '3', md: '0' }} spaceY="6">
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

                {/* Toolbar — flex-wrap: поиск занимает всю первую строку на мобиле, на desktop всё в одну линию */}
                <Flex gap="2" align="center" wrap="wrap">
                    {/* Поиск — занимает 100% на мобиле (basis), flex=1 на desktop */}
                    <Box
                        as="form"
                        onSubmit={handleSearchSubmit}
                        flex={{ base: '1 0 100%', md: '1 1 0' }}
                        minW="0"
                    >
                        <InputGroup startElement={<LuSearch size={16} />}>
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

                    {/* Фильтр */}
                    <Button
                        onClick={() => setShowFilters((s) => !s)}
                        variant={showFilters || activeFiltersCount > 0 ? 'subtle' : 'outline'}
                        colorPalette={showFilters || activeFiltersCount > 0 ? 'pecado' : 'gray'}
                        size="sm"
                        flexShrink="0"
                        aria-expanded={showFilters}
                        aria-label="Фильтры"
                        title="Фильтры"
                    >
                        <LuFilter size={16} />
                        <Box as="span" display={{ base: 'none', lg: 'inline' }}>
                            {showFilters ? 'Скрыть фильтры' : 'Фильтры'}
                        </Box>
                        {activeFiltersCount > 0 && (
                            <Badge colorPalette="pecado" variant="solid" borderRadius="full" fontSize="2xs" px="1.5" minW="4">
                                {activeFiltersCount}
                            </Badge>
                        )}
                    </Button>

                    {/* Сортировка — на мобиле тянется (flex=1), на desktop фиксированные 240px */}
                    <Box flex={{ base: '1 1 0', md: 'none' }} w={{ md: '240px' }} minW="0">
                        <Select.Root
                            collection={sortCollection}
                            value={[currentSort]}
                            onValueChange={(e) => handleSortChange(e.value[0] || 'added_desc')}
                            size="sm"
                        >
                            <Select.Trigger>
                                <Select.ValueText placeholder="Сортировка" />
                            </Select.Trigger>
                            <Select.Content>
                                {sortCollection.items.map((s) => (
                                    <Select.Item key={s.value} item={s}>
                                        {s.label}
                                    </Select.Item>
                                ))}
                            </Select.Content>
                        </Select.Root>
                    </Box>

                    {/* Правая группа — управление отображением (группировка + grid/list) */}
                    <Flex
                        align="center"
                        flexShrink="0"
                        border="1px solid"
                        borderColor="border"
                        borderRadius="md"
                        overflow="hidden"
                        _dark={{ borderColor: 'gray.600', bg: 'gray.800' }}
                    >
                        {[
                            {
                                key: 'group',
                                icon: LuListTree,
                                title: groupByCategory ? 'Не группировать' : 'Группировать по категории',
                                isActive: groupByCategory,
                                onClick: () => setGroupByCategory((v) => !v),
                                separator: true,
                            },
                            {
                                key: 'grid',
                                icon: LuGrid2X2,
                                title: 'Сетка',
                                isActive: view === 'grid',
                                onClick: () => setView('grid'),
                            },
                            {
                                key: 'list',
                                icon: LuLayoutList,
                                title: 'Список',
                                isActive: view === 'list',
                                onClick: () => setView('list'),
                            },
                        ].map(({ key, icon: BtnIcon, title, isActive, onClick, separator }) => (
                            <Flex
                                key={key}
                                as="button"
                                type="button"
                                title={title}
                                aria-label={title}
                                aria-pressed={isActive}
                                onClick={onClick}
                                align="center"
                                justify="center"
                                px="2.5"
                                h="32px"
                                cursor="pointer"
                                bg={isActive ? 'gray.100' : 'transparent'}
                                borderRight={separator ? '1px solid' : undefined}
                                borderColor={separator ? 'border' : undefined}
                                _hover={{ bg: isActive ? 'gray.100' : 'gray.50' }}
                                _dark={{
                                    bg: isActive ? 'gray.700' : 'transparent',
                                    borderColor: separator ? 'gray.600' : undefined,
                                    _hover: { bg: isActive ? 'gray.700' : 'gray.600' },
                                }}
                            >
                                <BtnIcon
                                    size={14}
                                    color={isActive ? 'var(--chakra-colors-gray-800)' : 'var(--chakra-colors-gray-400)'}
                                />
                            </Flex>
                        ))}
                    </Flex>
                </Flex>

                {/* Расширенные фильтры */}
                {showFilters && (
                    <Card.Root bg="bg" borderRadius="xl" border="1px solid" borderColor="border.muted">
                        <Card.Body p="4">
                            <Stack gap="4">
                                <Flex gap="4" direction={{ base: 'column', md: 'row' }}>
                                    <Field label="Наличие" flex="1">
                                        <Select.Root
                                            collection={availabilityCollection}
                                            value={localFilters.availability ? [localFilters.availability] : []}
                                            onValueChange={(e) => setLocalFilters({ ...localFilters, availability: e.value[0] || '' })}
                                            size="sm"
                                        >
                                            <Select.Trigger>
                                                <Select.ValueText placeholder="Любое" />
                                            </Select.Trigger>
                                            <Select.Content>
                                                {availabilityCollection.items.map((a) => (
                                                    <Select.Item key={a.value} item={a}>
                                                        {a.label}
                                                    </Select.Item>
                                                ))}
                                            </Select.Content>
                                        </Select.Root>
                                    </Field>

                                    <Field label="Бренд" flex="1">
                                        <Select.Root
                                            multiple
                                            collection={brandCollection}
                                            value={localFilters.brand_ids}
                                            onValueChange={(e) => setLocalFilters({ ...localFilters, brand_ids: e.value })}
                                            size="sm"
                                        >
                                            <Select.Trigger>
                                                <Select.ValueText placeholder="Все бренды">
                                                    {localFilters.brand_ids.length === 0 ? 'Все бренды' : `Выбрано: ${localFilters.brand_ids.length}`}
                                                </Select.ValueText>
                                            </Select.Trigger>
                                            <Select.Content>
                                                {brandCollection.items.map((b) => (
                                                    <Select.Item key={b.value} item={b}>
                                                        {b.label}
                                                    </Select.Item>
                                                ))}
                                            </Select.Content>
                                        </Select.Root>
                                    </Field>

                                    <Field label="Категория" flex="1">
                                        <Select.Root
                                            multiple
                                            collection={categoryCollection}
                                            value={localFilters.category_ids}
                                            onValueChange={(e) => setLocalFilters({ ...localFilters, category_ids: e.value })}
                                            size="sm"
                                        >
                                            <Select.Trigger>
                                                <Select.ValueText placeholder="Все категории">
                                                    {localFilters.category_ids.length === 0 ? 'Все категории' : `Выбрано: ${localFilters.category_ids.length}`}
                                                </Select.ValueText>
                                            </Select.Trigger>
                                            <Select.Content>
                                                {categoryCollection.items.map((c) => (
                                                    <Select.Item key={c.value} item={c}>
                                                        {c.label}
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
                </Box>
                {/* /конец верхнего блока с боковыми отступами */}

                {/* Товары или пустое состояние */}
                {items.length > 0 ? (
                    <>
                        {groupByCategory ? (
                            <Stack gap="6">
                                {groupedItems.map(([category, products]) => (
                                    <Box key={category} w="100%" minW="0">
                                        <Box px={{ base: '3', md: '0' }}>
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
                                        </Box>
                                        <ProductGrid
                                            products={products}
                                            view={view}
                                            templateColumns={{
                                                base: 'repeat(2, minmax(0, 1fr))',
                                                md: 'repeat(3, minmax(0, 1fr))',
                                                lg: 'repeat(4, minmax(0, 1fr))',
                                                xl: 'repeat(5, minmax(0, 1fr))',
                                            }}
                                        />
                                    </Box>
                                ))}
                            </Stack>
                        ) : (
                            <Box w="100%" minW="0">
                                <ProductGrid
                                    products={items}
                                    view={view}
                                    templateColumns={{
                                        base: 'repeat(2, minmax(0, 1fr))',
                                        md: 'repeat(3, minmax(0, 1fr))',
                                        lg: 'repeat(4, minmax(0, 1fr))',
                                        xl: 'repeat(5, minmax(0, 1fr))',
                                    }}
                                />
                            </Box>
                        )}

                        <Box px={{ base: '3', md: '0' }}>
                            <Pagination
                                currentPage={currentPage}
                                lastPage={lastPage}
                                pageNumbers={pageNumbers}
                                onPageChange={onPageChange}
                                total={total}
                            />
                        </Box>
                    </>
                ) : (
                    <Box px={{ base: '3', md: '0' }}>
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
                    </Box>
                )}
            </Box>
        </UserLayout>
    );
}
