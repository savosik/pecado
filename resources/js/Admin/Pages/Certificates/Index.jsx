import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, HStack, Text, IconButton, Button } from '@chakra-ui/react';
import { LuPlus, LuFile } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';

export default function Index({ certificates, filters }) {
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
    } = useResourceIndex('admin.certificates', filters, {
        entityLabel: 'Сертификат',
    });

    const columns = [
        {
            key: 'id',
            label: 'ID',
            sortable: true,
            width: '80px',
        },
        {
            key: 'name',
            label: 'Название',
            sortable: true,
            render: (name, item) => (
                <Box>
                    <Text fontWeight="medium">{name}</Text>
                    {item.external_id && <Text fontSize="xs" color="fg.muted">ID: {item.external_id}</Text>}
                </Box>
            ),
        },
        {
            key: 'issued_at',
            label: 'Дата выдачи',
            sortable: true,
            render: (date) => (
                <Text fontSize="sm" color="gray.600">
                    {date ? new Date(date).toLocaleString('ru-RU') : '—'}
                </Text>
            ),
        },
        {
            key: 'expires_at',
            label: 'Действует до',
            sortable: true,
            render: (date) => (
                <Text fontSize="sm" color="gray.600">
                    {date ? new Date(date).toLocaleString('ru-RU') : '—'}
                </Text>
            ),
        },
        {
            key: 'media',
            label: 'Файлы',
            render: (media) => (
                <HStack gap={2}>
                    {media?.map(m => (
                        <IconButton
                            key={m.id}
                            size="xs"
                            variant="subtle"
                            as="a"
                            href={m.original_url}
                            target="_blank"
                            title={m.file_name}
                        >
                            <LuFile />
                        </IconButton>
                    ))}
                    {(!media || media.length === 0) && <Text fontSize="xs" color="fg.muted">Нет файлов</Text>}
                </HStack>
            ),
        },
        createActionsColumn('admin.certificates', openDeleteDialog),
    ];

    return (
        <>
            <PageHeader
                title="Сертификаты"
                description="Управление сертификатами соответствия"
                actions={
                    <Button colorPalette="blue" onClick={() => router.visit(route('admin.certificates.create'))}>
                        <LuPlus /> Создать сертификат
                    </Button>
                }
            />

            <Box mb={4}>
                <SearchInput
                    value={searchQuery}
                    onChange={handleSearch}
                    placeholder="Поиск по названию или внешнему ID..."
                />
            </Box>

            <DataTable
                data={certificates.data}
                columns={columns}
                pagination={certificates}
                onSort={handleSort}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                perPage={filters.per_page}
                onPerPageChange={handlePerPageChange}
            />

            <ConfirmDialog
                open={deleteDialogOpen}
                onClose={closeDeleteDialog}
                onConfirm={confirmDelete}
                title="Удалить сертификат?"
                description={`Вы уверены, что хотите удалить сертификат "${entityToDelete?.name}"?`}
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
