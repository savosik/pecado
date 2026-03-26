import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, Text, Badge, HStack, IconButton } from '@chakra-ui/react';
import { LuPlus, LuCopy, LuLink } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';
import { toaster } from '@/components/ui/toaster';

const formatColors = {
    json: 'blue',
    csv: 'green',
    xml: 'orange',
    xls: 'purple',
};

export default function Index({ exports, filters }) {
    const {
        searchQuery,
        handleSearch,
        handleSort,
        deleteDialogOpen,
        openDeleteDialog,
        confirmDelete,
        closeDeleteDialog,
    } = useResourceIndex('admin.product-exports', filters, {
        entityLabel: 'выгрузку',
    });

    const copyUrl = (url) => {
        navigator.clipboard.writeText(url);
        toaster.create({
            title: 'Ссылка скопирована',
            type: 'success',
        });
    };

    const columns = [
        {
            key: 'id',
            label: 'ID',
            sortable: true,
            render: (_, row) => <Box fontFamily="mono" fontSize="sm">{row.id}</Box>,
        },
        {
            key: 'name',
            label: 'Название',
            sortable: true,
            render: (_, row) => <Text fontWeight="semibold">{row.name}</Text>,
        },
        {
            key: 'client_user',
            label: 'Клиент',
            render: (_, row) => (
                <Text fontSize="sm" color="fg.muted">
                    {row.client_user?.full_name || '—'}
                </Text>
            ),
        },
        {
            key: 'format',
            label: 'Формат',
            render: (_, row) => (
                <Badge colorPalette={formatColors[row.format] || 'gray'} variant="subtle" size="sm">
                    {row.format?.toUpperCase()}
                </Badge>
            ),
        },
        {
            key: 'filters_count',
            label: 'Фильтры',
            render: (_, row) => {
                const count = row.filters?.conditions?.length || row.filters?.length || 0;
                return <Text fontSize="sm" color="fg.muted">{count}</Text>;
            },
        },
        {
            key: 'fields_count',
            label: 'Полей',
            render: (_, row) => (
                <Text fontSize="sm" color="fg.muted">{row.fields?.length || 0}</Text>
            ),
        },
        {
            key: 'download_url',
            label: 'Ссылка',
            render: (_, row) => (
                <HStack gap={1}>
                    <Text fontSize="xs" color="blue.500" maxW="200px" truncate>
                        {row.download_url}
                    </Text>
                    <IconButton
                        size="xs"
                        variant="ghost"
                        onClick={() => copyUrl(row.download_url)}
                        aria-label="Копировать ссылку"
                    >
                        <LuCopy />
                    </IconButton>
                </HStack>
            ),
        },
        {
            key: 'is_active',
            label: 'Статус',
            render: (_, row) => (
                <Badge colorPalette={row.is_active ? 'green' : 'gray'} variant="subtle" size="sm">
                    {row.is_active ? 'Активна' : 'Неактивна'}
                </Badge>
            ),
        },
        {
            key: 'last_downloaded_at',
            label: 'Скачана',
            render: (_, row) => (
                <Text fontSize="sm" color="fg.muted">
                    {row.last_downloaded_at
                        ? new Date(row.last_downloaded_at).toLocaleDateString('ru-RU')
                        : '—'}
                </Text>
            ),
        },
        createActionsColumn('admin.product-exports', openDeleteDialog),
    ];

    return (
        <>
            <PageHeader
                title="Выгрузки товаров"
                onCreate={() => router.visit(route('admin.product-exports.create'))}
                createLabel="Создать выгрузку"
            />

            <Box mb={4}>
                <SearchInput
                    value={searchQuery}
                    onChange={handleSearch}
                    placeholder="Поиск по названию..."
                />
            </Box>

            <DataTable
                data={exports.data}
                columns={columns}
                pagination={exports}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                onSort={handleSort}
            />

            <ConfirmDialog
                open={deleteDialogOpen}
                onClose={closeDeleteDialog}
                onConfirm={confirmDelete}
                title="Удалить выгрузку?"
                description="Вы уверены, что хотите удалить эту выгрузку? Ссылка для скачивания перестанет работать. Это действие нельзя отменить."
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
