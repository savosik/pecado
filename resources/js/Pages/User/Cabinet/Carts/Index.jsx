import { useEffect, useMemo, useRef, useState } from 'react';
import {
    Box, Flex, Text, Card, HStack, VStack, Badge, Button, Stack,
    IconButton, Table, Input, InputGroup, Dialog, Portal,
} from '@chakra-ui/react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import CabinetLayout from '../CabinetLayout';
import {
    LuPlus, LuEye, LuTrash2, LuShoppingCart, LuStar,
    LuPencil, LuCheck, LuX, LuSearch, LuFilter,
    LuArrowDown, LuArrowUp, LuArrowUpDown,
} from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';
import { Field } from '@/components/ui/field';
import { Select } from '@/components/ui/select';
import { Checkbox } from '@/components/ui/checkbox';
import { MenuRoot, MenuTrigger, MenuContent, MenuItem } from '@/components/ui/menu';
import SelectedFilters from '@/components/cabinet/SelectedFilters';
import Pagination from '@/components/common/Pagination';
import { useSearchHistory } from '@/hooks/useSearchHistory';
import axios from 'axios';

const SORT_LABELS = {
    updated_at: 'Обновлена',
    created_at: 'Создана',
    name: 'Название',
    total_amount: 'Сумма',
    items_count: 'Позиций',
};

export default function Index({ carts = { data: [], current_page: 1, last_page: 1, total: 0 }, cartsCount = 0, filters = {}, sortFields = Object.keys(SORT_LABELS) }) {
    const { currency } = usePage().props;
    const currencyCode = currency?.code || 'RUB';
    const [editingId, setEditingId] = useState(null);
    const [editName, setEditName] = useState('');

    // Create dialog
    const [showCreate, setShowCreate] = useState(false);
    const [newCartName, setNewCartName] = useState('');
    const createInputRef = useRef(null);

    // Delete dialog
    const [deleteCart, setDeleteCart] = useState(null);

    // Search & filters
    const [showFilters, setShowFilters] = useState(false);
    const [search, setSearch] = useState(filters?.search || '');
    const [localFilters, setLocalFilters] = useState({
        amount_from: filters?.amount_from ?? '',
        amount_to: filters?.amount_to ?? '',
        items_count_from: filters?.items_count_from ?? '',
        items_count_to: filters?.items_count_to ?? '',
        only_empty: !!filters?.only_empty,
        only_active: !!filters?.only_active,
    });

    const navigateWithParams = (params) => {
        router.get('/cabinet/carts', { ...filters, ...params }, { preserveState: true, replace: true });
    };

    const { history: searchHistory, push: pushSearchHistory } = useSearchHistory('carts');
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
            amount_from: '', amount_to: '',
            items_count_from: '', items_count_to: '',
            only_empty: false, only_active: false,
        };
        setLocalFilters(reset);
        setSearch('');
        lastSubmittedSearch.current = '';
        navigateWithParams({ search: '', sort_by: 'updated_at', sort_order: 'desc', ...reset, page: 1 });
    };

    const handleSort = (field) => {
        const direction = filters?.sort_by === field && filters?.sort_order === 'asc' ? 'desc' : 'asc';
        navigateWithParams({ sort_by: field, sort_order: direction });
    };

    const handlePageChange = (page) => {
        navigateWithParams({ page });
    };

    const activeSort = sortFields.find((f) => f === filters?.sort_by);
    const sortIsActive = !!activeSort && filters?.sort_by !== 'updated_at';
    const SortIcon = filters?.sort_order === 'asc' ? LuArrowUp : LuArrowDown;

    const activeFiltersCount = useMemo(() => {
        let count = 0;
        for (const k of ['amount_from', 'amount_to', 'items_count_from', 'items_count_to']) {
            if (filters?.[k] !== '' && filters?.[k] !== null && filters?.[k] !== undefined) count++;
        }
        if (filters?.only_empty) count++;
        if (filters?.only_active) count++;
        return count;
    }, [filters]);

    const filterFields = useMemo(() => [
        { key: 'search', label: 'Поиск', formatter: (v) => `«${v}»` },
        { key: 'amount_from', label: 'Сумма от' },
        { key: 'amount_to', label: 'Сумма до' },
        { key: 'items_count_from', label: 'Позиций от' },
        { key: 'items_count_to', label: 'Позиций до' },
        { key: 'only_empty', label: 'Только пустые', formatter: (v) => v ? 'да' : null },
        { key: 'only_active', label: 'Только активная', formatter: (v) => v ? 'да' : null },
    ], []);

    const handleRemoveFilter = (key) => {
        if (key === 'search') {
            setSearch('');
            lastSubmittedSearch.current = '';
            navigateWithParams({ search: '', page: 1 });

            return;
        }
        if (key === 'only_empty' || key === 'only_active') {
            setLocalFilters({ ...localFilters, [key]: false });
            navigateWithParams({ [key]: false, page: 1 });

            return;
        }
        setLocalFilters({ ...localFilters, [key]: '' });
        navigateWithParams({ [key]: '', page: 1 });
    };

    const formatPrice = (val) =>
        new Intl.NumberFormat('ru-RU', { style: 'currency', currency: currencyCode, minimumFractionDigits: 0 }).format(val || 0);

    // === Create / Delete / Switch / Rename — без изменений по сравнению с предыдущей версией ===
    const openCreateDialog = () => {
        setNewCartName('');
        setShowCreate(true);
    };

    const confirmCreate = async () => {
        if (!newCartName.trim()) {
            toaster.create({ title: 'Введите название корзины', type: 'warning' });
            return;
        }
        setShowCreate(false);
        try {
            await axios.post('/api/cart/carts', { name: newCartName.trim() });
            toaster.create({ title: 'Корзина создана', type: 'success' });
            router.reload();
        } catch (e) {
            toaster.create({ title: e.response?.data?.message || 'Ошибка создания', type: 'error' });
        }
    };

    const confirmDelete = async () => {
        if (!deleteCart) return;
        try {
            await axios.delete(`/cabinet/carts/${deleteCart.id}`);
            toaster.create({ title: 'Корзина удалена', type: 'success' });
            setDeleteCart(null);
            router.reload();
        } catch (e) {
            toaster.create({ title: e.response?.data?.message || 'Ошибка удаления', type: 'error' });
            setDeleteCart(null);
        }
    };

    const handleSwitch = async (cart) => {
        try {
            await axios.post(`/cabinet/carts/${cart.id}/switch`);
            toaster.create({ title: `Корзина "${cart.name || '#' + cart.id}" стала активной`, type: 'success' });
            router.reload();
        } catch (e) {
            toaster.create({ title: e.response?.data?.message || 'Ошибка', type: 'error' });
        }
    };

    const startEdit = (cart) => {
        setEditingId(cart.id);
        setEditName(cart.name || '');
    };

    const cancelEdit = () => {
        setEditingId(null);
        setEditName('');
    };

    const saveRename = async (cart) => {
        if (!editName.trim()) {
            toaster.create({ title: 'Введите название', type: 'warning' });
            return;
        }
        try {
            await axios.patch(`/cabinet/carts/${cart.id}/rename`, { name: editName.trim() });
            toaster.create({ title: 'Название обновлено', type: 'success' });
            setEditingId(null);
            router.reload();
        } catch (e) {
            toaster.create({ title: e.response?.data?.message || 'Ошибка', type: 'error' });
        }
    };

    const renderName = (cart) => {
        if (editingId === cart.id) {
            return (
                <HStack gap="1">
                    <Input
                        size="sm" w="180px"
                        value={editName}
                        onChange={(e) => setEditName(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && saveRename(cart)}
                        autoFocus
                    />
                    <IconButton size="xs" variant="ghost" colorPalette="green" onClick={() => saveRename(cart)}>
                        <LuCheck />
                    </IconButton>
                    <IconButton size="xs" variant="ghost" onClick={cancelEdit}>
                        <LuX />
                    </IconButton>
                </HStack>
            );
        }
        return (
            <HStack gap="1" wrap="wrap">
                <Text fontWeight="600" fontSize="sm">
                    {cart.name || `Корзина #${cart.id}`}
                </Text>
                {cart.is_active && (
                    <Badge colorPalette="green" variant="subtle" fontSize="xs">Активная</Badge>
                )}
                {cart.match_source === 'composition' && (
                    <Badge colorPalette="purple" variant="subtle" fontSize="xs">по составу</Badge>
                )}
                <IconButton
                    size="xs" variant="ghost" color="gray.400"
                    aria-label="Переименовать"
                    onClick={() => startEdit(cart)}
                >
                    <LuPencil />
                </IconButton>
            </HStack>
        );
    };

    const cartsList = carts?.data ?? [];

    return (
        <CabinetLayout
            title="Мои корзины"
            actions={
                <Button onClick={openCreateDialog} bg="#9e1b32" color="white" _hover={{ bg: '#7a1527' }} size="sm">
                    <LuPlus /> Создать
                </Button>
            }
        >
            <Head title="Мои корзины — Pecado" />

            {/* Toolbar */}
            <Flex gap="2" mb="4" align="center" wrap="wrap">
                <Box as="form" onSubmit={handleSearchSubmit} flex="1" minW={{ base: '100%', md: '320px' }}>
                    <InputGroup startElement={<LuSearch size={16} />} flex="1">
                        <Input
                            placeholder="Поиск по имени корзины или товару…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            size="sm"
                            list="carts-search-history"
                        />
                    </InputGroup>
                    {searchHistory.length > 0 && (
                        <datalist id="carts-search-history">
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
                            {sortIsActive ? <SortIcon size={16} /> : <LuArrowUpDown size={16} />}
                            <Box as="span" display={{ base: 'none', md: 'inline' }}>
                                {SORT_LABELS[filters?.sort_by] || 'Сортировка'}
                            </Box>
                        </Button>
                    </MenuTrigger>
                    <MenuContent>
                        {sortFields.map((f) => {
                            const isActive = filters?.sort_by === f;
                            const ActiveIcon = filters?.sort_order === 'asc' ? LuArrowUp : LuArrowDown;
                            return (
                                <MenuItem
                                    key={f}
                                    value={f}
                                    onClick={() => handleSort(f)}
                                >
                                    <Flex align="center" justify="space-between" w="100%" gap="3">
                                        <Text fontWeight={isActive ? '600' : '400'}>{SORT_LABELS[f] || f}</Text>
                                        {isActive && (
                                            <Box color="pecado.500" _dark={{ color: 'pecado.300' }}>
                                                <ActiveIcon size={14} />
                                            </Box>
                                        )}
                                    </Flex>
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
                                <Field label="Сумма от" flex="1">
                                    <Input type="number" size="sm" value={localFilters.amount_from}
                                        onChange={(e) => setLocalFilters({ ...localFilters, amount_from: e.target.value })}
                                        placeholder="0" />
                                </Field>
                                <Field label="Сумма до" flex="1">
                                    <Input type="number" size="sm" value={localFilters.amount_to}
                                        onChange={(e) => setLocalFilters({ ...localFilters, amount_to: e.target.value })}
                                        placeholder="∞" />
                                </Field>
                                <Field label="Позиций от" flex="1">
                                    <Input type="number" size="sm" min="0" value={localFilters.items_count_from}
                                        onChange={(e) => setLocalFilters({ ...localFilters, items_count_from: e.target.value })} />
                                </Field>
                                <Field label="Позиций до" flex="1">
                                    <Input type="number" size="sm" min="0" value={localFilters.items_count_to}
                                        onChange={(e) => setLocalFilters({ ...localFilters, items_count_to: e.target.value })} />
                                </Field>
                            </Flex>
                            <Flex gap="6" wrap="wrap">
                                <Checkbox
                                    checked={localFilters.only_empty}
                                    onCheckedChange={(e) => setLocalFilters({ ...localFilters, only_empty: !!e.checked })}
                                >
                                    Только пустые
                                </Checkbox>
                                <Checkbox
                                    checked={localFilters.only_active}
                                    onCheckedChange={(e) => setLocalFilters({ ...localFilters, only_active: !!e.checked })}
                                >
                                    Только активная
                                </Checkbox>
                            </Flex>
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

            {cartsList.length === 0 ? (
                <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} borderRadius="xl" border="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }} _dark={{ bg: 'gray.800', borderColor: 'gray.700' }}>
                    <Card.Body p="8" textAlign="center">
                        <VStack gap="3">
                            <Flex align="center" justify="center" w="14" h="14" borderRadius="full" bg="gray.100" _dark={{ bg: 'gray.700' }}>
                                <LuShoppingCart size={24} color="gray" />
                            </Flex>
                            <Text color="gray.500">
                                {search || activeFiltersCount > 0 ? 'Корзин по запросу не найдено' : 'Корзин пока нет'}
                            </Text>
                            {(search || activeFiltersCount > 0) ? (
                                <Button variant="outline" size="sm" onClick={handleResetFilters}>
                                    <LuX /> Сбросить фильтры
                                </Button>
                            ) : (
                                <Button onClick={openCreateDialog} bg="#9e1b32" color="white" _hover={{ bg: '#7a1527' }} size="sm">
                                    <LuPlus /> Создать корзину
                                </Button>
                            )}
                        </VStack>
                    </Card.Body>
                </Card.Root>
            ) : (
                <>
                    <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} borderRadius="xl" border="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }} _dark={{ bg: 'gray.800', borderColor: 'gray.700' }} overflow="hidden">
                        {/* Desktop Table */}
                        <Box display={{ base: 'none', md: 'block' }}>
                            <Table.Root bg={{ base: 'white', _dark: 'gray.800' }} size="sm">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>Название</Table.ColumnHeader>
                                        <Table.ColumnHeader>Позиций</Table.ColumnHeader>
                                        <Table.ColumnHeader>Единиц</Table.ColumnHeader>
                                        <Table.ColumnHeader>Сумма</Table.ColumnHeader>
                                        <Table.ColumnHeader>Обновлена</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">Действия</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {cartsList.map((c) => (
                                        <Table.Row key={c.id}>
                                            <Table.Cell>{renderName(c)}</Table.Cell>
                                            <Table.Cell>
                                                <Badge colorPalette={c.items_count > 0 ? 'blue' : 'gray'} variant="subtle">
                                                    {c.items_count}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell fontSize="sm">{c.total_quantity}</Table.Cell>
                                            <Table.Cell fontSize="sm" fontWeight="600">{formatPrice(c.total_amount)}</Table.Cell>
                                            <Table.Cell fontSize="xs" color="gray.400">{c.updated_at || '—'}</Table.Cell>
                                            <Table.Cell textAlign="right">
                                                <HStack gap="1" justify="flex-end">
                                                    <IconButton
                                                        size="xs" variant="ghost"
                                                        aria-label={c.is_active ? 'Активная' : 'Сделать активной'}
                                                        title={c.is_active ? 'Активная' : 'Сделать активной'}
                                                        onClick={() => !c.is_active && handleSwitch(c)}
                                                        cursor={c.is_active ? 'default' : 'pointer'}
                                                        color={c.is_active ? 'yellow.500' : 'gray.400'}
                                                    >
                                                        <LuStar style={c.is_active ? { fill: 'currentColor' } : {}} />
                                                    </IconButton>
                                                    <IconButton
                                                        as={Link} href={`/cabinet/carts/${c.id}`}
                                                        size="xs" variant="ghost" colorPalette="blue"
                                                        aria-label="Просмотр"
                                                    >
                                                        <LuEye />
                                                    </IconButton>
                                                    {c.can_delete && (
                                                        <IconButton
                                                            size="xs" variant="ghost" colorPalette="red"
                                                            aria-label="Удалить"
                                                            onClick={() => setDeleteCart(c)}
                                                        >
                                                            <LuTrash2 />
                                                        </IconButton>
                                                    )}
                                                </HStack>
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Box>

                        {/* Mobile Cards */}
                        <VStack display={{ base: 'flex', md: 'none' }} gap="0" align="stretch" separator={<Box borderTop="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }} _dark={{ borderColor: 'gray.700' }} />}>
                            {cartsList.map((c) => (
                                <Box key={c.id} p="4">
                                    <Flex justify="space-between" align="start" mb="2">
                                        <Box flex="1" minW="0">
                                            {renderName(c)}
                                            <HStack gap="3" flexWrap="wrap" mt="1">
                                                <Text fontSize="xs" color="gray.400">
                                                    {c.items_count} позиций · {c.total_quantity} ед.
                                                </Text>
                                                <Text fontSize="sm" fontWeight="700" color="green.600">
                                                    {formatPrice(c.total_amount)}
                                                </Text>
                                            </HStack>
                                        </Box>
                                        <HStack gap="1" flexShrink="0">
                                            <IconButton
                                                size="sm" variant="ghost"
                                                aria-label={c.is_active ? 'Активная' : 'Сделать активной'}
                                                onClick={() => !c.is_active && handleSwitch(c)}
                                                cursor={c.is_active ? 'default' : 'pointer'}
                                                color={c.is_active ? 'yellow.500' : 'gray.400'}
                                            >
                                                <LuStar style={c.is_active ? { fill: 'currentColor' } : {}} />
                                            </IconButton>
                                            <IconButton as={Link} href={`/cabinet/carts/${c.id}`} size="sm" variant="ghost" colorPalette="blue" aria-label="Просмотр">
                                                <LuEye />
                                            </IconButton>
                                            {c.can_delete && (
                                                <IconButton size="sm" variant="ghost" colorPalette="red" aria-label="Удалить" onClick={() => setDeleteCart(c)}>
                                                    <LuTrash2 />
                                                </IconButton>
                                            )}
                                        </HStack>
                                    </Flex>
                                </Box>
                            ))}
                        </VStack>
                    </Card.Root>

                    {carts?.last_page > 1 && (
                        <Box mt="4">
                            <Pagination
                                currentPage={carts.current_page}
                                lastPage={carts.last_page}
                                onPageChange={handlePageChange}
                                total={carts.total}
                            />
                        </Box>
                    )}
                </>
            )}

            {/* Create Cart Dialog */}
            <Dialog.Root open={showCreate} onOpenChange={({ open }) => !open && setShowCreate(false)} initialFocusEl={() => createInputRef.current}>
                <Portal>
                    <Dialog.Backdrop />
                    <Dialog.Positioner>
                        <Dialog.Content>
                            <Dialog.Header>
                                <Dialog.Title>Новая корзина</Dialog.Title>
                            </Dialog.Header>
                            <Dialog.Body>
                                <Text mb="2" fontSize="sm" color="gray.500">
                                    Введите название для новой корзины
                                </Text>
                                <Input
                                    ref={createInputRef}
                                    value={newCartName}
                                    onChange={(e) => setNewCartName(e.target.value)}
                                    onKeyDown={(e) => e.key === 'Enter' && confirmCreate()}
                                    placeholder="Например: Для офиса"
                                />
                            </Dialog.Body>
                            <Dialog.Footer>
                                <Button variant="outline" onClick={() => setShowCreate(false)}>
                                    Отмена
                                </Button>
                                <Button bg="#9e1b32" color="white" _hover={{ bg: '#7a1527' }} onClick={confirmCreate}>
                                    Создать
                                </Button>
                            </Dialog.Footer>
                        </Dialog.Content>
                    </Dialog.Positioner>
                </Portal>
            </Dialog.Root>

            {/* Delete Confirm Dialog */}
            <Dialog.Root open={!!deleteCart} onOpenChange={({ open }) => !open && setDeleteCart(null)}>
                <Portal>
                    <Dialog.Backdrop />
                    <Dialog.Positioner>
                        <Dialog.Content>
                            <Dialog.Header>
                                <Dialog.Title>Удаление корзины</Dialog.Title>
                            </Dialog.Header>
                            <Dialog.Body>
                                <Text>
                                    Удалить корзину «{deleteCart?.name || `#${deleteCart?.id}`}»? Все товары из неё будут удалены.
                                </Text>
                            </Dialog.Body>
                            <Dialog.Footer>
                                <Button variant="outline" onClick={() => setDeleteCart(null)}>
                                    Отмена
                                </Button>
                                <Button colorPalette="red" onClick={confirmDelete}>
                                    Удалить
                                </Button>
                            </Dialog.Footer>
                        </Dialog.Content>
                    </Dialog.Positioner>
                </Portal>
            </Dialog.Root>
        </CabinetLayout>
    );
}
