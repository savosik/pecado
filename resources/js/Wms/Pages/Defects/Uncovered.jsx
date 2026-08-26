import { Head, router, usePage } from '@inertiajs/react';
import {
    Badge,
    Box,
    Card,
    HStack,
    SimpleGrid,
    Table,
    Text,
    VStack,
} from '@chakra-ui/react';
import { LuCheck, LuPackagePlus, LuTriangleAlert } from 'react-icons/lu';
import WmsLayout from '@/Wms/Layouts/WmsLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { SearchInput } from '@/Admin/Components/SearchInput';
import { Pagination } from '@/Admin/Components/Pagination';
import { Button } from '@/components/ui/button';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';
import RowActions from '@/shared/Panel/RowActions';

const FILTERS = [
    { value: 'uncovered', label: 'Не покрыто' },
    { value: 'over', label: 'Расхождения' },
    { value: 'all', label: 'Все позиции' },
];

const EMPTY_TEXT = {
    uncovered: 'Непокрытых остатков нет — на каждый остаток склада некондиции заведена партия.',
    over: 'Расхождений нет — партий нигде не больше, чем числится в 1С.',
    all: 'На складах некондиции нет ни остатков, ни партий.',
};

function StatCard({ label, value, hint, tone }) {
    return (
        <Card.Root>
            <Card.Body>
                <Text fontSize="xs" color="fg.muted">{label}</Text>
                <Text fontSize="2xl" fontWeight="bold" color={tone}>{value}</Text>
                {hint && <Text fontSize="xs" color="fg.muted">{hint}</Text>}
            </Card.Body>
        </Card.Root>
    );
}

/** Непокрытое количество: положительное — остаток без партий, отрицательное — расхождение. */
function UncoveredValue({ value }) {
    if (value > 0) {
        return (
            <Text fontSize="sm" fontWeight="bold" color="orange.500" fontVariantNumeric="tabular-nums">
                {value}
            </Text>
        );
    }

    if (value < 0) {
        return (
            <HStack gap={1} justify="end">
                <LuTriangleAlert size={14} color="var(--chakra-colors-red-500)" />
                <Text fontSize="sm" fontWeight="bold" color="red.500" fontVariantNumeric="tabular-nums">
                    {value}
                </Text>
            </HStack>
        );
    }

    return (
        <HStack gap={1} justify="end" color="fg.muted">
            <LuCheck size={14} />
            <Text fontSize="sm">0</Text>
        </HStack>
    );
}

/**
 * Строка отчёта карточкой — мобильный вариант: таблица из шести колонок
 * в 360px не помещается, а горизонтальный скролл на складе одной рукой не листают.
 */
function CoverageMobileCard({ row, actions }) {
    return (
        <Box borderWidth="1px" borderColor="border" borderRadius="md" p={3}>
            <VStack align="stretch" gap={3}>
                <Box>
                    <Text fontSize="sm" fontWeight="medium" lineClamp={2}>{row.product_name}</Text>
                    <Text fontSize="xs" color="fg.muted">
                        {row.product_sku || '—'} · {row.warehouse_name}
                    </Text>
                </Box>

                <HStack gap={4} flexWrap="wrap" fontSize="sm">
                    <Text>
                        <Text as="span" color="fg.muted">Остаток 1С: </Text>
                        {row.stock_quantity}
                    </Text>
                    <Text>
                        <Text as="span" color="fg.muted">В партиях: </Text>
                        {row.covered_quantity}
                    </Text>
                    <HStack gap={1}>
                        <Text as="span" color="fg.muted">Не покрыто: </Text>
                        <UncoveredValue value={row.uncovered_quantity} />
                    </HStack>
                </HStack>

                {row.idle_quantity > 0 && (
                    <Text fontSize="xs" color="fg.muted">
                        {row.idle_quantity} шт. в партиях без цены или снятых с публикации — на витрине не предлагаются.
                    </Text>
                )}

                <RowActions {...actions} size="md" />
            </VStack>
        </Box>
    );
}

export default function DefectsUncovered() {
    const { rows, filters, stats, warehouses } = usePage().props;
    /**
     * Строка — это товар на складе, а не партия, поэтому карандаша нет:
     * править нечего, пока партия не заведена. Форма открывается с уже
     * подставленными товаром и складом.
     */
    const actionsFor = (row) => ({
        extra: row.uncovered_quantity > 0
            ? [{
                icon: LuPackagePlus,
                label: 'Завести партию',
                href: `/wms/defects/create?product_id=${row.product_id}&warehouse_id=${row.warehouse_id}`,
                permission: 'wms-defects.create',
            }]
            : [],
    });

    const applyFilters = (next) => {
        router.get('/wms/defects/uncovered', { ...filters, ...next }, {
            preserveState: true,
            replace: true,
        });
    };

    return (
        <>
            <Head title="Не закрыто партиями — Склад" />
            <PageHeader
                title="Не закрыто партиями"
                description="Остатки склада некондиции, на которые не заведены партии брака. Пока партии нет, товар нигде не предлагается."
            />

            <VStack gap={4} align="stretch">
                <SimpleGrid columns={{ base: 2, md: 4 }} gap={3}>
                    <StatCard
                        label="Позиций не покрыто"
                        value={stats.uncovered_positions}
                        hint="товар + склад"
                        tone={stats.uncovered_positions > 0 ? 'orange.500' : undefined}
                    />
                    <StatCard
                        label="Штук не покрыто"
                        value={stats.uncovered_units}
                        hint="висят на складе"
                        tone={stats.uncovered_units > 0 ? 'orange.500' : undefined}
                    />
                    <StatCard
                        label="Расхождений"
                        value={stats.over_positions}
                        hint="партий больше, чем в 1С"
                        tone={stats.over_positions > 0 ? 'red.500' : undefined}
                    />
                    <StatCard
                        label="Штук не в продаже"
                        value={stats.idle_units}
                        hint="партии без цены и публикации"
                    />
                </SimpleGrid>

                <Card.Root>
                    <Card.Body>
                        <VStack gap={3} align="stretch">
                            <HStack gap={2} flexWrap="wrap">
                                <Box flex="1" minW={{ base: '100%', md: '240px' }}>
                                    <SearchInput
                                        value={filters.search || ''}
                                        onChange={(value) => applyFilters({ search: value, page: 1 })}
                                        placeholder="Поиск по товару или артикулу..."
                                    />
                                </Box>

                                {warehouses.length > 1 && (
                                    <NativeSelectRoot size="sm" w={{ base: '100%', md: '220px' }}>
                                        <NativeSelectField
                                            value={filters.warehouse_id ? String(filters.warehouse_id) : ''}
                                            onChange={(event) => applyFilters({
                                                warehouse_id: event.target.value,
                                                page: 1,
                                            })}
                                        >
                                            <option value="">Все склады некондиции</option>
                                            {warehouses.map((warehouse) => (
                                                <option key={warehouse.id} value={warehouse.id}>
                                                    {warehouse.name}
                                                </option>
                                            ))}
                                        </NativeSelectField>
                                    </NativeSelectRoot>
                                )}

                                <HStack gap={1} flexWrap="wrap">
                                    {FILTERS.map((item) => (
                                        <Button
                                            key={item.value}
                                            size={{ base: 'sm', md: 'xs' }}
                                            variant={(filters.filter || 'uncovered') === item.value ? 'solid' : 'outline'}
                                            onClick={() => applyFilters({ filter: item.value, page: 1 })}
                                        >
                                            {item.label}
                                        </Button>
                                    ))}
                                </HStack>
                            </HStack>

                            {rows.data.length === 0 ? (
                                <HStack gap={2} color="fg.muted" py={6} justify="center">
                                    <LuCheck size={18} />
                                    <Text fontSize="sm" textAlign="center">
                                        {EMPTY_TEXT[filters.filter] || EMPTY_TEXT.uncovered}
                                    </Text>
                                </HStack>
                            ) : (
                                <>
                                    <VStack gap={2} align="stretch" display={{ base: 'flex', lg: 'none' }}>
                                        {rows.data.map((row) => (
                                            <CoverageMobileCard
                                                key={`${row.product_id}-${row.warehouse_id}`}
                                                row={row}
                                                actions={actionsFor(row)}
                                            />
                                        ))}
                                    </VStack>

                                    <Box overflowX="auto" display={{ base: 'none', lg: 'block' }}>
                                        <Table.Root size="sm" variant="line">
                                            <Table.Header>
                                                <Table.Row>
                                                    <Table.ColumnHeader>Товар</Table.ColumnHeader>
                                                    <Table.ColumnHeader>Склад</Table.ColumnHeader>
                                                    <Table.ColumnHeader textAlign="end">Остаток 1С</Table.ColumnHeader>
                                                    <Table.ColumnHeader textAlign="end">В партиях</Table.ColumnHeader>
                                                    <Table.ColumnHeader textAlign="end">Не покрыто</Table.ColumnHeader>
                                                    <Table.ColumnHeader textAlign="end">Действия</Table.ColumnHeader>
                                                </Table.Row>
                                            </Table.Header>
                                            <Table.Body>
                                                {rows.data.map((row) => (
                                                    <Table.Row key={`${row.product_id}-${row.warehouse_id}`}>
                                                        <Table.Cell maxW="420px">
                                                            <VStack align="start" gap={0}>
                                                                <Text fontSize="sm" lineClamp={2}>{row.product_name}</Text>
                                                                <Text fontSize="xs" color="fg.muted">
                                                                    {row.product_sku || '—'}
                                                                </Text>
                                                            </VStack>
                                                        </Table.Cell>
                                                        <Table.Cell>
                                                            <Text fontSize="sm" color="fg.muted">{row.warehouse_name}</Text>
                                                        </Table.Cell>
                                                        <Table.Cell textAlign="end" fontVariantNumeric="tabular-nums">
                                                            {row.stock_quantity}
                                                        </Table.Cell>
                                                        <Table.Cell textAlign="end">
                                                            <VStack align="end" gap={0}>
                                                                <Text fontSize="sm" fontVariantNumeric="tabular-nums">
                                                                    {row.covered_quantity}
                                                                </Text>
                                                                {row.idle_quantity > 0 && (
                                                                    <Badge size="xs" colorPalette="gray" variant="subtle">
                                                                        {row.idle_quantity} не в продаже
                                                                    </Badge>
                                                                )}
                                                            </VStack>
                                                        </Table.Cell>
                                                        <Table.Cell textAlign="end">
                                                            <UncoveredValue value={row.uncovered_quantity} />
                                                        </Table.Cell>
                                                        <Table.Cell>
                                                            <RowActions {...actionsFor(row)} size="sm" />
                                                        </Table.Cell>
                                                    </Table.Row>
                                                ))}
                                            </Table.Body>
                                        </Table.Root>
                                    </Box>
                                </>
                            )}

                            <Pagination
                                pagination={rows}
                                onPageChange={(page) => applyFilters({ page })}
                            />
                        </VStack>
                    </Card.Body>
                </Card.Root>
            </VStack>
        </>
    );
}

DefectsUncovered.layout = (page) => <WmsLayout>{page}</WmsLayout>;
