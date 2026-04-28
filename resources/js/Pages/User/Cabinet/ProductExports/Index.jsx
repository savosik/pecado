import { useEffect, useMemo, useRef, useState } from 'react';
import {
    Box, Flex, Text, Card, HStack, VStack, Badge, Button, Stack,
    IconButton, Table, Input, InputGroup, Dialog, Portal,
} from '@chakra-ui/react';
import { Head, Link, router } from '@inertiajs/react';
import CabinetLayout from '../CabinetLayout';
import {
    LuPlus, LuPencil, LuTrash2, LuCopy, LuSearch, LuFileDown,
    LuFilter, LuArrowUpDown, LuX,
} from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';
import { Field } from '@/components/ui/field';
import { MenuRoot, MenuTrigger, MenuContent, MenuItem } from '@/components/ui/menu';
import SelectedFilters from '@/components/cabinet/SelectedFilters';
import { useSearchHistory } from '@/hooks/useSearchHistory';

const formatColors = {
    json: 'blue',
    csv: 'green',
    xml: 'orange',
    xls: 'purple',
};

const STATUS_LABELS = {
    true: 'Активные',
    false: 'Архивные',
};

export default function Index({
    exports,
    filters = {},
    sortOptions = {},
}) {
    const [deleteExport, setDeleteExport] = useState(null);

    const [search, setSearch] = useState(filters?.search || '');
    const [showFilters, setShowFilters] = useState(false);
    const [localFilters, setLocalFilters] = useState({
        created_from: filters?.created_from ?? '',
        created_to: filters?.created_to ?? '',
        last_downloaded_from: filters?.last_downloaded_from ?? '',
        last_downloaded_to: filters?.last_downloaded_to ?? '',
        is_active: filters?.is_active === null || filters?.is_active === undefined
            ? ''
            : (filters.is_active ? '1' : '0'),
    });

    const navigateWithParams = (params) => {
        router.get('/cabinet/product-exports', { ...filters, ...params }, { preserveState: true, replace: true });
    };

    const { history: searchHistory, push: pushSearchHistory } = useSearchHistory('product-exports');
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
        navigateWithParams({ ...localFilters, page: 1 });
    };

    const handleResetFilters = () => {
        const reset = {
            created_from: '', created_to: '',
            last_downloaded_from: '', last_downloaded_to: '',
            is_active: '',
        };
        setLocalFilters(reset);
        setSearch('');
        lastSubmittedSearch.current = '';
        navigateWithParams({ search: '', sort: 'created_desc', ...reset, page: 1 });
    };

    const handleSortChange = (sort) => {
        navigateWithParams({ sort, page: 1 });
    };

    const activeFiltersCount = useMemo(() => {
        let count = 0;
        for (const k of ['created_from', 'created_to', 'last_downloaded_from', 'last_downloaded_to']) {
            if (filters?.[k]) count++;
        }
        if (filters?.is_active !== null && filters?.is_active !== undefined) count++;
        return count;
    }, [filters]);

    const filterFields = useMemo(() => [
        { key: 'search', label: 'Поиск', formatter: (v) => `«${v}»` },
        { key: 'created_from', label: 'Создана от' },
        { key: 'created_to', label: 'Создана до' },
        { key: 'last_downloaded_from', label: 'Скачана от' },
        { key: 'last_downloaded_to', label: 'Скачана до' },
        {
            key: 'is_active',
            label: 'Статус',
            formatter: (v) => {
                if (v === true || v === '1' || v === 1) return STATUS_LABELS['true'];
                if (v === false || v === '0' || v === 0) return STATUS_LABELS['false'];
                return null;
            },
        },
    ], []);

    const handleRemoveFilter = (key) => {
        if (key === 'search') {
            setSearch('');
            lastSubmittedSearch.current = '';
            navigateWithParams({ search: '', page: 1 });

            return;
        }
        if (key === 'is_active') {
            setLocalFilters({ ...localFilters, is_active: '' });
            navigateWithParams({ is_active: '', page: 1 });

            return;
        }
        setLocalFilters({ ...localFilters, [key]: '' });
        navigateWithParams({ [key]: '', page: 1 });
    };

    const copyUrl = (url) => {
        navigator.clipboard.writeText(url);
        toaster.create({ title: 'Ссылка скопирована', type: 'success' });
    };

    const confirmDelete = () => {
        if (!deleteExport) return;
        router.delete(`/cabinet/product-exports/${deleteExport.id}`, {
            onSuccess: () => {
                toaster.create({ title: 'Выгрузка удалена', type: 'success' });
                setDeleteExport(null);
            },
            onError: () => {
                toaster.create({ title: 'Ошибка удаления', type: 'error' });
                setDeleteExport(null);
            },
        });
    };

    const sortIsActive = filters?.sort && filters.sort !== 'created_desc';
    const sortLabel = sortOptions?.[filters?.sort] || 'Сортировка';
    const exportsList = exports?.data ?? [];

    return (
        <CabinetLayout
            title="Конструктор выгрузок"
            actions={
                <Button
                    as={Link}
                    href="/cabinet/product-exports/create"
                    bg="#9e1b32"
                    color="white"
                    _hover={{ bg: '#7a1527' }}
                    size="sm"
                >
                    <LuPlus /> Создать выгрузку
                </Button>
            }
        >
            <Head title="Конструктор выгрузок — Pecado" />

            <Text fontSize="sm" color="gray.500" mb="4">
                Создавайте произвольные выгрузки с выбором полей, фильтров и формата.
            </Text>

            {/* Toolbar */}
            <Flex gap="2" mb="4" align="center" wrap="wrap">
                <Box as="form" onSubmit={handleSearchSubmit} flex="1" minW={{ base: '100%', md: '320px' }}>
                    <InputGroup startElement={<LuSearch size={16} />} flex="1">
                        <Input
                            placeholder="Поиск по названию или фильтрам выгрузки…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            size="sm"
                            list="product-exports-search-history"
                        />
                    </InputGroup>
                    {searchHistory.length > 0 && (
                        <datalist id="product-exports-search-history">
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

                <MenuRoot positioning={{ placement: 'bottom-end' }}>
                    <MenuTrigger asChild>
                        <Button
                            variant={sortIsActive ? 'subtle' : 'outline'}
                            colorPalette={sortIsActive ? 'pecado' : 'gray'}
                            size="sm"
                            flexShrink="0"
                            aria-label="Сортировка"
                        >
                            <LuArrowUpDown size={16} />
                            <Box as="span" display={{ base: 'none', md: 'inline' }}>
                                {sortLabel}
                            </Box>
                        </Button>
                    </MenuTrigger>
                    <MenuContent>
                        {Object.entries(sortOptions).map(([value, label]) => {
                            const isActive = filters?.sort === value;

                            return (
                                <MenuItem
                                    key={value}
                                    value={value}
                                    onClick={() => handleSortChange(value)}
                                >
                                    <Text fontWeight={isActive ? '600' : '400'}>{label}</Text>
                                </MenuItem>
                            );
                        })}
                    </MenuContent>
                </MenuRoot>
            </Flex>

            {showFilters && (
                <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} mb="4" borderRadius="xl" border="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }} _dark={{ borderColor: 'gray.700' }}>
                    <Card.Body p="4">
                        <Stack gap="4">
                            <Flex gap="4" direction={{ base: 'column', md: 'row' }}>
                                <Field label="Создана от" flex="1">
                                    <Input type="date" size="sm" value={localFilters.created_from}
                                        onChange={(e) => setLocalFilters({ ...localFilters, created_from: e.target.value })} />
                                </Field>
                                <Field label="Создана до" flex="1">
                                    <Input type="date" size="sm" value={localFilters.created_to}
                                        onChange={(e) => setLocalFilters({ ...localFilters, created_to: e.target.value })} />
                                </Field>
                                <Field label="Скачана от" flex="1">
                                    <Input type="date" size="sm" value={localFilters.last_downloaded_from}
                                        onChange={(e) => setLocalFilters({ ...localFilters, last_downloaded_from: e.target.value })} />
                                </Field>
                                <Field label="Скачана до" flex="1">
                                    <Input type="date" size="sm" value={localFilters.last_downloaded_to}
                                        onChange={(e) => setLocalFilters({ ...localFilters, last_downloaded_to: e.target.value })} />
                                </Field>
                            </Flex>
                            <Field label="Статус">
                                <Box
                                    as="select"
                                    value={localFilters.is_active}
                                    onChange={(e) => setLocalFilters({ ...localFilters, is_active: e.target.value })}
                                    borderRadius="md"
                                    border="1px solid"
                                    borderColor={{ base: 'gray.200', _dark: 'gray.600' }}
                                    bg={{ base: 'white', _dark: 'gray.800' }}
                                    px="3"
                                    py="1.5"
                                    fontSize="sm"
                                    maxW="200px"
                                >
                                    <option value="">Любой</option>
                                    <option value="1">Активные</option>
                                    <option value="0">Архивные</option>
                                </Box>
                            </Field>
                            <Flex justify="end" gap="2">
                                <Button size="sm" variant="ghost" onClick={handleResetFilters}>
                                    <LuX size={14} /> Сбросить
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

            {exportsList.length === 0 ? (
                <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} borderRadius="xl" border="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }} _dark={{ bg: 'gray.800', borderColor: 'gray.700' }}>
                    <Card.Body p="8" textAlign="center">
                        <VStack gap="3">
                            <Flex
                                align="center" justify="center" w="14" h="14" borderRadius="full"
                                bg="gray.100" _dark={{ bg: 'gray.700' }}
                            >
                                <LuFileDown size={24} color="gray" />
                            </Flex>
                            <Text color="gray.500">
                                {search || activeFiltersCount > 0
                                    ? 'Выгрузок по запросу не найдено'
                                    : 'Выгрузок пока нет'}
                            </Text>
                            {(search || activeFiltersCount > 0) ? (
                                <Button variant="outline" size="sm" onClick={handleResetFilters}>
                                    <LuX /> Сбросить фильтры
                                </Button>
                            ) : (
                                <>
                                    <Text fontSize="xs" color="gray.400">
                                        Создайте произвольную выгрузку с выбором полей, фильтров и формата
                                    </Text>
                                    <Button
                                        as={Link}
                                        href="/cabinet/product-exports/create"
                                        bg="#9e1b32"
                                        color="white"
                                        _hover={{ bg: '#7a1527' }}
                                        size="sm"
                                    >
                                        <LuPlus /> Создать выгрузку
                                    </Button>
                                </>
                            )}
                        </VStack>
                    </Card.Body>
                </Card.Root>
            ) : (
                <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} borderRadius="xl" border="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }} _dark={{ bg: 'gray.800', borderColor: 'gray.700' }} overflow="hidden">
                    {/* Desktop Table */}
                    <Box display={{ base: 'none', md: 'block' }}>
                        <Table.Root bg={{ base: 'white', _dark: 'gray.800' }} size="sm">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeader>Название</Table.ColumnHeader>
                                    <Table.ColumnHeader>Формат</Table.ColumnHeader>
                                    <Table.ColumnHeader>Фильтры</Table.ColumnHeader>
                                    <Table.ColumnHeader>Полей</Table.ColumnHeader>
                                    <Table.ColumnHeader>Ссылка</Table.ColumnHeader>
                                    <Table.ColumnHeader>Статус</Table.ColumnHeader>
                                    <Table.ColumnHeader>Скачана</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Действия</Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {exportsList.map((exp) => {
                                    const filtersCount = exp.filters?.conditions?.length || exp.filters?.length || 0;
                                    return (
                                        <Table.Row key={exp.id}>
                                            <Table.Cell>
                                                <Text fontWeight="600" fontSize="sm">{exp.name}</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge colorPalette={formatColors[exp.format] || 'gray'} variant="subtle" size="sm">
                                                    {exp.format?.toUpperCase()}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell fontSize="sm" color="gray.500">{filtersCount}</Table.Cell>
                                            <Table.Cell fontSize="sm" color="gray.500">{exp.fields?.length || 0}</Table.Cell>
                                            <Table.Cell>
                                                <HStack gap={1}>
                                                    <Text fontSize="xs" color="blue.500" maxW="150px" truncate>
                                                        {exp.download_url}
                                                    </Text>
                                                    <IconButton
                                                        size="xs"
                                                        variant="ghost"
                                                        onClick={() => copyUrl(exp.download_url)}
                                                        aria-label="Копировать ссылку"
                                                    >
                                                        <LuCopy />
                                                    </IconButton>
                                                </HStack>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge colorPalette={exp.is_active ? 'green' : 'gray'} variant="subtle" size="sm">
                                                    {exp.is_active ? 'Активна' : 'Неактивна'}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell fontSize="sm" color="gray.500">
                                                {exp.last_downloaded_at
                                                    ? new Date(exp.last_downloaded_at).toLocaleDateString('ru-RU')
                                                    : '—'}
                                            </Table.Cell>
                                            <Table.Cell textAlign="right">
                                                <HStack gap="1" justify="flex-end">
                                                    <IconButton
                                                        as={Link}
                                                        href={`/cabinet/product-exports/${exp.id}/edit`}
                                                        size="xs"
                                                        variant="ghost"
                                                        colorPalette="blue"
                                                        aria-label="Редактировать"
                                                    >
                                                        <LuPencil />
                                                    </IconButton>
                                                    <IconButton
                                                        size="xs"
                                                        variant="ghost"
                                                        colorPalette="red"
                                                        aria-label="Удалить"
                                                        onClick={() => setDeleteExport(exp)}
                                                    >
                                                        <LuTrash2 />
                                                    </IconButton>
                                                </HStack>
                                            </Table.Cell>
                                        </Table.Row>
                                    );
                                })}
                            </Table.Body>
                        </Table.Root>
                    </Box>

                    {/* Mobile Cards */}
                    <VStack display={{ base: 'flex', md: 'none' }} gap="0" align="stretch"
                        separator={<Box borderTop="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }} _dark={{ borderColor: 'gray.700' }} />}
                    >
                        {exportsList.map((exp) => (
                            <Flex key={exp.id} p="4" align="center" justify="space-between">
                                <Box flex="1" minW="0">
                                    <Text fontWeight="600" fontSize="sm" noOfLines={1}>{exp.name}</Text>
                                    <HStack gap="2" mt="1">
                                        <Badge colorPalette={formatColors[exp.format] || 'gray'} variant="subtle" size="sm">
                                            {exp.format?.toUpperCase()}
                                        </Badge>
                                        <Badge colorPalette={exp.is_active ? 'green' : 'gray'} variant="subtle" size="sm">
                                            {exp.is_active ? 'Активна' : 'Неактивна'}
                                        </Badge>
                                    </HStack>
                                </Box>
                                <HStack gap="1" flexShrink="0">
                                    <IconButton
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => copyUrl(exp.download_url)}
                                        aria-label="Копировать ссылку"
                                    >
                                        <LuCopy />
                                    </IconButton>
                                    <IconButton
                                        as={Link}
                                        href={`/cabinet/product-exports/${exp.id}/edit`}
                                        size="sm"
                                        variant="ghost"
                                        colorPalette="blue"
                                        aria-label="Редактировать"
                                    >
                                        <LuPencil />
                                    </IconButton>
                                    <IconButton
                                        size="sm"
                                        variant="ghost"
                                        colorPalette="red"
                                        aria-label="Удалить"
                                        onClick={() => setDeleteExport(exp)}
                                    >
                                        <LuTrash2 />
                                    </IconButton>
                                </HStack>
                            </Flex>
                        ))}
                    </VStack>

                    {/* Pagination */}
                    {exports.last_page > 1 && (
                        <Flex justify="center" p="3" borderTop="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }} _dark={{ borderColor: 'gray.700' }}>
                            <HStack gap="1">
                                {exports.links.map((link, i) => (
                                    <Button
                                        key={i}
                                        size="xs"
                                        variant={link.active ? 'solid' : 'ghost'}
                                        bg={link.active ? '#9e1b32' : undefined}
                                        color={link.active ? 'white' : undefined}
                                        disabled={!link.url}
                                        onClick={() => link.url && router.visit(link.url)}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </HStack>
                        </Flex>
                    )}
                </Card.Root>
            )}

            {/* Delete Confirm Dialog */}
            <Dialog.Root open={!!deleteExport} onOpenChange={({ open }) => !open && setDeleteExport(null)}>
                <Portal>
                    <Dialog.Backdrop />
                    <Dialog.Positioner>
                        <Dialog.Content>
                            <Dialog.Header>
                                <Dialog.Title>Удаление выгрузки</Dialog.Title>
                            </Dialog.Header>
                            <Dialog.Body>
                                <Text>
                                    Удалить выгрузку «{deleteExport?.name}»? Ссылка для скачивания перестанет работать. Это действие нельзя отменить.
                                </Text>
                            </Dialog.Body>
                            <Dialog.Footer>
                                <Button variant="outline" onClick={() => setDeleteExport(null)}>Отмена</Button>
                                <Button colorPalette="red" onClick={confirmDelete}>Удалить</Button>
                            </Dialog.Footer>
                        </Dialog.Content>
                    </Dialog.Positioner>
                </Portal>
            </Dialog.Root>
        </CabinetLayout>
    );
}
