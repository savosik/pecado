import { useEffect, useMemo, useRef, useState } from 'react';
import {
    Box, Flex, HStack, VStack, Text, Badge, Button, Input, InputGroup,
    Table, IconButton, createListCollection,
} from '@chakra-ui/react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    LuSearch, LuChevronLeft, LuChevronRight, LuArrowRightLeft,
    LuPlus, LuMinus, LuArrowRight,
} from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';
import { Field } from '@/components/ui/field';
import { Select } from '@/components/ui/select';
import SelectedFilters from '@/components/cabinet/SelectedFilters';
import ExportMenu from '@/components/cabinet/ExportMenu';

const TYPE_META = {
    added: { label: 'Добавлен', color: 'green', icon: LuPlus },
    removed: { label: 'Выбыл', color: 'red', icon: LuMinus },
    changed: { label: 'Изменено количество', color: 'orange', icon: LuArrowRight },
};

export default function OrderChangesIndex({ filters, types = [], exportEnabled = false }) {
    const { rows } = usePage().props;
    const [search, setSearch] = useState(filters?.search || '');

    const navigateWithParams = (params) => {
        router.get('/cabinet/order-changes', { ...filters, ...params }, {
            preserveState: true,
            replace: true,
        });
    };

    // Debounce 400 мс для поля поиска.
    const lastSubmittedSearch = useRef(filters?.search || '');
    useEffect(() => {
        if (search === lastSubmittedSearch.current) return;
        const handle = setTimeout(() => {
            lastSubmittedSearch.current = search;
            navigateWithParams({ search, page: 1 });
        }, 400);
        return () => clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const typeCollection = useMemo(
        () => createListCollection({ items: types.map((t) => ({ label: t.label, value: t.value })) }),
        [types]
    );

    const selectedTypes = Array.isArray(filters?.type) ? filters.type : (filters?.type ? [filters.type] : []);

    const handlePageChange = (page) => navigateWithParams({ page });

    const filterFields = useMemo(() => [
        { key: 'search', label: 'Поиск', formatter: (v) => `«${v}»` },
        { key: 'type', label: 'Тип', formatter: (v) => TYPE_META[v]?.label || v },
        { key: 'date_from', label: 'Дата от' },
        { key: 'date_to', label: 'Дата до' },
    ], []);

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
        }
        navigateWithParams({ [key]: nextValue, page: 1 });
    };

    const handleResetAll = () => {
        setSearch('');
        lastSubmittedSearch.current = '';
        navigateWithParams({ search: '', type: [], date_from: '', date_to: '', page: 1 });
    };

    const hasActiveFilters = !!search || selectedTypes.length > 0 || filters?.date_from || filters?.date_to;

    return (
        <CabinetLayout title="Изменения заказов">
            <Head title="Изменения заказов — Pecado" />

            <Text color="gray.600" _dark={{ color: 'gray.400' }} fontSize="sm" mb="4">
                Движения товарного состава по вашим заказам: добавленные и выбывшие позиции, изменения количества.
                Разнонаправленные движения по одному товару свёрнуты в итоговое «было → стало».
            </Text>

            {/* Панель фильтров */}
            <Flex gap="2" mb="4" align="end" wrap="wrap">
                <Box as="form" onSubmit={(e) => { e.preventDefault(); lastSubmittedSearch.current = search; navigateWithParams({ search, page: 1 }); }} flex="1" minW="240px">
                    <InputGroup startElement={<LuSearch size={16} />}>
                        <Input
                            placeholder="Поиск по номеру заказа или товару…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            size="sm"
                        />
                    </InputGroup>
                </Box>

                <Field label="Тип изменения" w={{ base: '100%', md: '220px' }}>
                    <Select.Root
                        multiple
                        size="sm"
                        collection={typeCollection}
                        value={selectedTypes}
                        onValueChange={(e) => navigateWithParams({ type: e.value, page: 1 })}
                    >
                        <Select.Trigger>
                            <Select.ValueText placeholder="Все типы">
                                {selectedTypes.length === 0 ? 'Все типы' : `Выбрано: ${selectedTypes.length}`}
                            </Select.ValueText>
                        </Select.Trigger>
                        <Select.Content>
                            {typeCollection.items.map((t) => (
                                <Select.Item key={t.value} item={t}>{t.label}</Select.Item>
                            ))}
                        </Select.Content>
                    </Select.Root>
                </Field>

                <Field label="Дата от" w={{ base: '48%', md: '150px' }}>
                    <Input
                        type="date"
                        size="sm"
                        value={filters?.date_from || ''}
                        onChange={(e) => navigateWithParams({ date_from: e.target.value, page: 1 })}
                    />
                </Field>
                <Field label="Дата до" w={{ base: '48%', md: '150px' }}>
                    <Input
                        type="date"
                        size="sm"
                        value={filters?.date_to || ''}
                        onChange={(e) => navigateWithParams({ date_to: e.target.value, page: 1 })}
                    />
                </Field>

                {exportEnabled && (
                    <ExportMenu basePath="/cabinet/order-changes/export" filters={{ ...filters, search }} />
                )}
            </Flex>

            <SelectedFilters
                filters={{ ...filters, search }}
                fields={filterFields}
                onRemove={handleRemoveFilter}
                onResetAll={hasActiveFilters ? handleResetAll : undefined}
            />

            {rows.data.length === 0 ? (
                <Box bg="bg" borderRadius="xl" border="1px solid" borderColor="border.muted" p="10" textAlign="center">
                    <VStack gap="3">
                        <Flex align="center" justify="center" w="16" h="16" borderRadius="full" bg="bg.muted" mx="auto">
                            <LuArrowRightLeft size={26} color="var(--chakra-colors-gray-400)" />
                        </Flex>
                        <Text fontWeight="600" fontSize="lg">Изменений пока нет</Text>
                        <Text color="gray.500" fontSize="sm">Здесь появятся изменения товарного состава ваших заказов</Text>
                    </VStack>
                </Box>
            ) : (
                <>
                    <Box bg="bg" borderRadius="xl" border="1px solid" borderColor="border.muted" overflowX="auto">
                        <Table.Root size="sm" stickyHeader interactive>
                            <Table.Header>
                                <Table.Row bg="bg.muted">
                                    <Table.ColumnHeader whiteSpace="nowrap">Время</Table.ColumnHeader>
                                    <Table.ColumnHeader whiteSpace="nowrap">Заказ</Table.ColumnHeader>
                                    <Table.ColumnHeader whiteSpace="nowrap">Тип изменения</Table.ColumnHeader>
                                    <Table.ColumnHeader>Товар</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="end" whiteSpace="nowrap">Было</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="end" whiteSpace="nowrap">Стало</Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {rows.data.map((row, i) => {
                                    const meta = TYPE_META[row.type] || {};
                                    const Icon = meta.icon;
                                    const grew = row.to > row.from;
                                    return (
                                        <Table.Row key={`${row.order_id}-${i}`}>
                                            <Table.Cell whiteSpace="nowrap" color="gray.600" _dark={{ color: 'gray.400' }} fontSize="xs">
                                                {row.changed_at || '—'}
                                            </Table.Cell>
                                            <Table.Cell whiteSpace="nowrap">
                                                <Link href={`/cabinet/orders/${row.order_id}`}>
                                                    <Text as="span" fontFamily="mono" fontWeight="600" color="pecado.600" _dark={{ color: 'pecado.300' }} _hover={{ textDecoration: 'underline' }}>
                                                        {row.order_number}
                                                    </Text>
                                                </Link>
                                            </Table.Cell>
                                            <Table.Cell whiteSpace="nowrap">
                                                <Badge colorPalette={meta.color || 'gray'} variant="subtle" borderRadius="full" gap="1" px="2">
                                                    {Icon && <Icon size={11} />}
                                                    {row.type_label}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell>
                                                {row.slug ? (
                                                    <Box as="a" href={`/products/${row.slug}`} color="pecado.600" fontWeight="500"
                                                        _hover={{ textDecoration: 'underline', color: 'pecado.700' }}
                                                        _dark={{ color: 'pecado.300', _hover: { color: 'pecado.200' } }}>
                                                        {row.product_name}
                                                    </Box>
                                                ) : (
                                                    <Text as="span" color="fg.muted">{row.product_name}</Text>
                                                )}
                                            </Table.Cell>
                                            <Table.Cell textAlign="end" fontFamily="mono" color="gray.500">
                                                {row.from}
                                            </Table.Cell>
                                            <Table.Cell textAlign="end" fontFamily="mono" fontWeight="700"
                                                color={grew ? 'green.600' : 'red.600'}
                                                _dark={{ color: grew ? 'green.300' : 'red.300' }}>
                                                {row.to}
                                            </Table.Cell>
                                        </Table.Row>
                                    );
                                })}
                            </Table.Body>
                        </Table.Root>
                    </Box>

                    {rows.last_page > 1 && (
                        <Flex justify="center" align="center" gap="2" mt="6">
                            <IconButton variant="outline" size="sm" onClick={() => handlePageChange(rows.current_page - 1)} disabled={rows.current_page <= 1} aria-label="Предыдущая страница">
                                <LuChevronLeft size={16} />
                            </IconButton>
                            <Text fontSize="sm" color="fg.muted" px="2">
                                Стр. {rows.current_page} из {rows.last_page}
                            </Text>
                            <IconButton variant="outline" size="sm" onClick={() => handlePageChange(rows.current_page + 1)} disabled={rows.current_page >= rows.last_page} aria-label="Следующая страница">
                                <LuChevronRight size={16} />
                            </IconButton>
                        </Flex>
                    )}
                </>
            )}
        </CabinetLayout>
    );
}
