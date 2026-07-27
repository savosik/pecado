import { router, Link } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog, FilterPanel } from '@/Admin/Components';
import { Box, Text, Button, Badge, HStack, VStack } from '@chakra-ui/react';
import { NativeSelectRoot, NativeSelectField } from '@/components/ui/native-select';
import { LuPlus } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';
import { usePermission } from '@/Admin/hooks/usePermission';

const STATUS_COLORS = {
    active: 'green',
    disabled: 'gray',
    scheduled: 'blue',
    finished: 'orange',
};

export default function Index({ rules, promotions = [], filters }) {
    const { can } = usePermission();
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
                    {row.promotion && (
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
        createActionsColumn('admin.promotion-rules', openDeleteDialog, { permissionPrefix: 'promotion-rules' }),
    ];

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
            </FilterPanel>

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
