import { router, Link } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, Text, Badge } from '@chakra-ui/react';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';

const businessTypeLabels = {
    sex_shop: 'Секс-шоп',
    online_store: 'Интернет-магазин',
    marketplace: 'Маркетплейс',
    showroom: 'Шоурум',
    other: 'Другое',
};

export default function Index({ questionnaires, filters }) {
    const {
        searchQuery,
        handleSearch,
        handleSort,
        deleteDialogOpen,
        entityToDelete,
        openDeleteDialog,
        confirmDelete,
        closeDeleteDialog,
    } = useResourceIndex('admin.user-questionnaires', filters, {
        entityLabel: 'Анкета',
    });

    const columns = [
        {
            key: 'id',
            label: 'ID',
            sortable: true,
            render: (_, row) => <Box fontFamily="mono" fontSize="sm">{row.id}</Box>,
        },
        {
            key: 'user',
            label: 'Пользователь',
            render: (_, row) => row.user ? (
                <Box>
                    <Link href={route('admin.users.edit', row.user.id)}>
                        <Text color="blue.600" _hover={{ textDecoration: 'underline' }} fontWeight="medium">
                            {row.user.name}
                        </Text>
                    </Link>
                    <Text fontSize="xs" color="gray.500">{row.user.email}</Text>
                </Box>
            ) : <Text color="gray.400">—</Text>,
        },
        {
            key: 'business_type',
            label: 'Тип бизнеса',
            render: (_, row) => row.business_type ? (
                <Badge variant="outline" colorPalette="blue" size="sm">
                    {businessTypeLabels[row.business_type] || row.business_type}
                </Badge>
            ) : <Text color="gray.400">—</Text>,
        },
        {
            key: 'business_name',
            label: 'Компания',
            render: (_, row) => row.business_name || <Text color="gray.400">—</Text>,
        },
        {
            key: 'completed_at',
            label: 'Статус',
            sortable: true,
            render: (_, row) => row.completed_at ? (
                <Badge variant="solid" colorPalette="green" size="sm">Заполнена</Badge>
            ) : (
                <Badge variant="outline" colorPalette="orange" size="sm">Не завершена</Badge>
            ),
        },
        {
            key: 'created_at',
            label: 'Дата создания',
            sortable: true,
            render: (_, row) => (
                <Text fontSize="sm" color="gray.600">
                    {row.created_at ? new Date(row.created_at).toLocaleString('ru-RU') : '—'}
                </Text>
            ),
        },
        createActionsColumn('admin.user-questionnaires', openDeleteDialog, { showView: false }),
    ];

    return (
        <>
            <PageHeader title="Анкеты пользователей" />

            <Box mb={4}>
                <SearchInput
                    value={searchQuery}
                    onChange={handleSearch}
                    placeholder="Поиск по имени, email или компании..."
                />
            </Box>

            <DataTable
                data={questionnaires.data}
                columns={columns}
                pagination={questionnaires}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                onSort={handleSort}
            />

            <ConfirmDialog
                open={deleteDialogOpen}
                onClose={closeDeleteDialog}
                onConfirm={confirmDelete}
                title="Удалить анкету?"
                description="Вы уверены, что хотите удалить эту анкету? Это действие нельзя отменить."
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
