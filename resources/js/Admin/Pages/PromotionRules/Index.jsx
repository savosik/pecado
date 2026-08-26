import { useMemo, useState } from 'react';
import { router, Link } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog, FilterPanel, Pagination } from '@/Admin/Components';
import { Box, Card, Text, Button, Badge, HStack, VStack } from '@chakra-ui/react';
import { NativeSelectRoot, NativeSelectField } from '@/components/ui/native-select';
import { Checkbox } from '@/components/ui/checkbox';
import { LuPlus, LuChevronDown, LuChevronRight } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';
import { usePermission } from '@/Admin/hooks/usePermission';

const STATUS_COLORS = {
    active: 'green',
    disabled: 'gray',
    scheduled: 'blue',
    finished: 'orange',
};

/**
 * Группировка строк текущей страницы по акции.
 *
 * Именно текущей страницы: пагинация серверная, и делать вид, что группа
 * содержит все правила акции, было бы враньём — счётчик в заголовке говорит,
 * сколько правил группы попало на эту страницу.
 */
const groupByPromotion = (rows) => {
    const groups = new Map();

    rows.forEach((row) => {
        const key = row.promotion?.id ?? 'none';

        if (!groups.has(key)) {
            groups.set(key, {
                key,
                title: row.promotion?.name || 'Без привязки к акции',
                promotionId: row.promotion?.id ?? null,
                rows: [],
            });
        }

        groups.get(key).rows.push(row);
    });

    return [...groups.values()];
};

export default function Index({ rules, promotions = [], filters }) {
    const { can } = usePermission();
    const [grouped, setGrouped] = useState(false);
    const [collapsed, setCollapsed] = useState({});
    const {
        searchQuery,
        handleSearch,
        handleSort,
        handlePerPageChange,
        deleteDialogOpen,
        entityToDelete,
        openDeleteDialog,
        confirmDelete,
        closeDeleteDialog,
        navigate,
    } = useResourceIndex('admin.promotion-rules', filters, {
        entityLabel: 'Правило',
        deleteSuccessTitle: 'Правило акции удалено',
    });

    const applyFilter = (key, value) => {
        navigate({ ...filters, [key]: value || undefined });
    };

    const columns = [
        {
            key: 'name',
            label: 'Название',
            sortable: true,
            render: (_, row) => (
                <VStack align="start" gap={0}>
                    <Text fontWeight="medium">{row.name}</Text>
                    {/* В сгруппированном виде название акции уже в заголовке группы */}
                    {!grouped && row.promotion && (
                        <Link href={route('admin.promotions.edit', row.promotion.id)}>
                            <Text fontSize="xs" color="blue.500">
                                Акция: {row.promotion.name}
                            </Text>
                        </Link>
                    )}
                </VStack>
            ),
        },
        {
            key: 'mode',
            label: 'Режим',
            render: (mode, row) => (
                <Badge colorPalette={mode === 'issue' ? 'green' : 'gray'} variant="subtle">
                    {row.mode_label}
                </Badge>
            ),
        },
        {
            key: 'status',
            label: 'Статус',
            render: (status, row) => (
                <Badge colorPalette={STATUS_COLORS[status] || 'gray'} variant="subtle">
                    {row.status_label}
                </Badge>
            ),
        },
        {
            key: 'period',
            label: 'Период',
            render: (period) => (
                <Text fontSize="sm" color="fg.muted" whiteSpace="nowrap">
                    {period}
                </Text>
            ),
        },
        {
            key: 'condition_summary',
            label: 'Условие',
            render: (summary) => (
                <Text fontSize="sm" maxW="320px">
                    {summary}
                </Text>
            ),
        },
        {
            key: 'reward_summary',
            label: 'Награда',
            render: (summary) => (
                <Text fontSize="sm" maxW="280px">
                    {summary}
                </Text>
            ),
        },
        {
            key: 'priority',
            label: 'Приоритет',
            sortable: true,
        },
        {
            key: 'issued_count',
            label: 'Выдано',
            render: (value) => (
                <Text fontSize="sm" color="fg.muted">
                    {value ?? '—'}
                </Text>
            ),
        },
        createActionsColumn('admin.promotion-rules', openDeleteDialog, { permissionPrefix: 'promotion-rules', showView: false }),
    ];

    const groups = useMemo(() => groupByPromotion(rules.data), [rules.data]);

    return (
        <>
            <PageHeader
                title="Правила акций"
                description="Конструктор промо: условие срабатывания и промо-позиция в награду"
                actions={
                    can('promotion-rules.create') && (
                        <Button colorPalette="blue" onClick={() => router.visit(route('admin.promotion-rules.create'))}>
                            <LuPlus /> Создать правило
                        </Button>
                    )
                }
            />

            <Box mb={4}>
                <SearchInput
                    value={searchQuery}
                    onChange={handleSearch}
                    placeholder="Поиск по названию правила..."
                />
            </Box>

            <FilterPanel
                onClear={() => navigate({ per_page: filters.per_page })}
                showClear={Boolean(filters.status || filters.mode || filters.promotion_id)}
            >
                <HStack gap={2}>
                    <Text fontSize="sm" color="fg.muted">Статус:</Text>
                    <NativeSelectRoot size="sm" width="180px">
                        <NativeSelectField
                            value={filters.status || ''}
                            onChange={(e) => applyFilter('status', e.target.value)}
                        >
                            <option value="">Любой</option>
                            <option value="active">Активно</option>
                            <option value="disabled">Выключено</option>
                            <option value="scheduled">Не начата</option>
                            <option value="finished">Завершена</option>
                        </NativeSelectField>
                    </NativeSelectRoot>
                </HStack>

                <HStack gap={2}>
                    <Text fontSize="sm" color="fg.muted">Режим:</Text>
                    <NativeSelectRoot size="sm" width="200px">
                        <NativeSelectField
                            value={filters.mode || ''}
                            onChange={(e) => applyFilter('mode', e.target.value)}
                        >
                            <option value="">Любой</option>
                            <option value="info">Только показ</option>
                            <option value="issue">Выдача промо-позиций</option>
                        </NativeSelectField>
                    </NativeSelectRoot>
                </HStack>

                <HStack gap={2}>
                    <Text fontSize="sm" color="fg.muted">Акция:</Text>
                    <NativeSelectRoot size="sm" width="240px">
                        <NativeSelectField
                            value={filters.promotion_id || ''}
                            onChange={(e) => applyFilter('promotion_id', e.target.value)}
                        >
                            <option value="">Любая</option>
                            {promotions.map((promotion) => (
                                <option key={promotion.id} value={promotion.id}>
                                    {promotion.name}
                                </option>
                            ))}
                        </NativeSelectField>
                    </NativeSelectRoot>
                </HStack>

                <Checkbox checked={grouped} onCheckedChange={(e) => setGrouped(e.checked)}>
                    Группировать по акции
                </Checkbox>
            </FilterPanel>

            {grouped ? (
                <VStack align="stretch" gap={4}>
                    {groups.map((group) => {
                        const isCollapsed = Boolean(collapsed[group.key]);

                        return (
                            <Card.Root key={group.key} borderWidth="1px">
                                <Card.Header py={3}>
                                    <HStack justify="space-between" gap={3} wrap="wrap">
                                        <HStack
                                            gap={2}
                                            cursor="pointer"
                                            onClick={() => setCollapsed((current) => ({
                                                ...current,
                                                [group.key]: !current[group.key],
                                            }))}
                                        >
                                            {isCollapsed ? <LuChevronRight /> : <LuChevronDown />}
                                            <Text fontWeight="semibold">{group.title}</Text>
                                            <Badge colorPalette="gray" variant="subtle">
                                                правил на странице: {group.rows.length}
                                            </Badge>
                                        </HStack>

                                        {group.promotionId && (
                                            <Link href={route('admin.promotions.edit', group.promotionId)}>
                                                <Text fontSize="sm" color="blue.500">Открыть акцию</Text>
                                            </Link>
                                        )}
                                    </HStack>
                                </Card.Header>

                                {!isCollapsed && (
                                    <Card.Body pt={0}>
                                        <DataTable
                                            data={group.rows}
                                            columns={columns}
                                            emptyMessage="Правил нет"
                                        />
                                    </Card.Body>
                                )}
                            </Card.Root>
                        );
                    })}

                    {groups.length === 0 && (
                        <Text color="fg.muted">Правила акций пока не созданы</Text>
                    )}

                    {/* Пагинация серверная и общая для всех групп: страница режется по правилам */}
                    <Pagination
                        pagination={rules}
                        perPage={filters.per_page}
                        onPerPageChange={handlePerPageChange}
                    />
                </VStack>
            ) : (
                <DataTable
                    data={rules.data}
                    columns={columns}
                    pagination={rules}
                    onSort={handleSort}
                    sortColumn={filters.sort_by}
                    sortDirection={filters.sort_order}
                    perPage={filters.per_page}
                    onPerPageChange={handlePerPageChange}
                    emptyMessage="Правила акций пока не созданы"
                />
            )}

            <ConfirmDialog
                open={deleteDialogOpen}
                onClose={closeDeleteDialog}
                onConfirm={confirmDelete}
                title="Удалить правило акции?"
                description={`Правило «${entityToDelete?.name || 'без названия'}» уйдёт в архив. Уже выданные по нему промо-позиции сохранят ссылку на правило.`}
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
