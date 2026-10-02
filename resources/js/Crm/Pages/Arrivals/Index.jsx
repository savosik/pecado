import { useCallback } from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, HStack, Text, VStack } from '@chakra-ui/react';
import RowActions from '@/shared/Panel/RowActions';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { SearchInput } from '@/Admin/Components/SearchInput';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';
import { Button } from '@/components/ui/button';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';

const quantityFormat = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 3 });
const pieces = (value) => `${quantityFormat.format(value)} шт`;

/**
 * Ожидаемые поступления товара из 1С (v16.16.0).
 *
 * Ответ на вопрос клиента «когда приедет?»: по складу — дата или «дата уточняется»
 * и количество. Данные ведут закупки в 1С; сайт их только показывает и только
 * сотрудникам. «Дата уточняется» — дата не внесена либо прошла, а товар ещё
 * не принят на склад.
 */
export default function Index({ rows, filters, warehouses = [], updatedAt = null }) {
    const { searchQuery, handleSearch } = useResourceIndex('crm.arrivals', filters, {
        entityLabel: 'Товар',
    });

    const applyFilters = useCallback((patch) => {
        router.get(route('crm.arrivals.index'), { ...filters, ...patch }, {
            preserveState: true,
            replace: true,
        });
    }, [filters]);

    const resetFilters = useCallback(() => {
        router.get(route('crm.arrivals.index'), { per_page: filters.per_page }, {
            preserveState: false,
            replace: true,
        });
    }, [filters.per_page]);

    const hasFilters = Boolean(filters.search || filters.warehouse_id);

    const columns = [
        {
            key: 'name',
            label: 'Товар',
            render: (value, row) => (
                <VStack align="start" gap={0}>
                    <Text fontSize="sm">{value}</Text>
                    <Text fontSize="xs" color="fg.muted">
                        {[row.sku ? `арт. ${row.sku}` : null, row.brand].filter(Boolean).join(' · ')}
                    </Text>
                </VStack>
            ),
        },
        {
            key: 'warehouses',
            label: 'Склад',
            render: (value) => (
                <VStack align="start" gap={2}>
                    {value.map((warehouse) => (
                        <Box key={warehouse.warehouse_id}>
                            <Text fontSize="sm">{warehouse.warehouse}</Text>
                            <Text fontSize="xs" color="fg.muted">
                                {warehouse.free > 0 ? `сейчас свободно ${pieces(warehouse.free)}` : 'сейчас нет в наличии'}
                            </Text>
                        </Box>
                    ))}
                </VStack>
            ),
        },
        {
            key: 'expected',
            label: 'Когда и сколько',
            render: (_, row) => (
                <VStack align="start" gap={2}>
                    {row.warehouses.map((warehouse) => (
                        <VStack key={warehouse.warehouse_id} align="start" gap={0}>
                            {warehouse.rows.map((line) => (
                                <HStack key={line.date ?? 'pending'} gap={2}>
                                    <Text
                                        fontSize="sm"
                                        minW="110px"
                                        color={line.date ? undefined : 'fg.muted'}
                                    >
                                        {line.label}
                                    </Text>
                                    <Text fontSize="sm" fontWeight="semibold">{pieces(line.quantity)}</Text>
                                </HStack>
                            ))}
                        </VStack>
                    ))}
                </VStack>
            ),
        },
        {
            key: 'summary',
            label: 'Всего ждём',
            render: (value) => (value ? <Text fontSize="sm">{pieces(value.quantity)}</Text> : '—'),
        },
        {
            key: 'actions',
            label: 'Действия',
            render: (_, row) => (
                <RowActions
                    size="xs"
                    view={{ href: row.slug ? `/products/${row.slug}` : null, label: 'Карточка товара' }}
                />
            ),
        },
    ];

    return (
        <>
            <Head title="CRM — Ожидаемые поступления" />
            <PageHeader
                title="Ожидаемые поступления"
                description={
                    'Когда и сколько товара ждём на складе — по данным закупок в 1С. '
                    + '«Дата уточняется» — дата ещё не внесена либо прошла, а товар не принят на склад. '
                    + 'Раздел служебный: клиенты этих данных не видят.'
                }
            />

            <HStack gap={3} align="center" wrap="wrap" mb={4}>
                <Box flex="1" minW="260px">
                    <SearchInput
                        value={searchQuery}
                        onChange={handleSearch}
                        placeholder="Название, артикул или штрихкод..."
                    />
                </Box>

                {warehouses.length > 1 && (
                    <Box minW="220px">
                        <NativeSelectRoot size="sm">
                            <NativeSelectField
                                value={filters.warehouse_id ?? ''}
                                onChange={(event) => applyFilters({ warehouse_id: event.target.value || undefined })}
                            >
                                <option value="">Склад — любой</option>
                                {warehouses.map((warehouse) => (
                                    <option key={warehouse.value} value={warehouse.value}>{warehouse.label}</option>
                                ))}
                            </NativeSelectField>
                        </NativeSelectRoot>
                    </Box>
                )}

                {hasFilters && (
                    <Button size="sm" variant="outline" onClick={resetFilters}>
                        Сбросить
                    </Button>
                )}

                <Text fontSize="xs" color="fg.muted">
                    {updatedAt ? `Обновлено из 1С: ${updatedAt}` : 'Данные из 1С ещё не поступали'}
                </Text>
            </HStack>

            <DataTable
                data={rows.data}
                columns={columns}
                pagination={rows}
                perPage={filters.per_page}
                onPerPageChange={(perPage) => applyFilters({ per_page: perPage })}
                emptyMessage={hasFilters
                    ? 'По этому отбору поступлений не ожидается'
                    : 'Ожидаемых поступлений нет'}
            />
        </>
    );
}

Index.layout = (page) => <CrmLayout>{page}</CrmLayout>;
