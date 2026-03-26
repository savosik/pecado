import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, Text, Button, Badge, HStack } from '@chakra-ui/react';
import { LuPlus } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';

const getStatusColor = (status) => {
    const colors = {
        processing: 'yellow',
        active: 'green',
        blocked: 'red',
    };
    return colors[status] || 'gray';
};

export default function Index({ users, filters, statuses, statusCounts }) {
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
    } = useResourceIndex('admin.users', filters, {
        entityLabel: 'Пользователь',
    });

    const handleStatusFilter = (statusValue) => {
        router.get(route('admin.users.index'), {
            ...filters,
            status: statusValue || undefined,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const columns = [
        {
            key: 'id',
            label: 'ID',
            sortable: true,
            width: '80px',
        },
        {
            key: 'full_name',
            label: 'ФИО',
            sortable: false,
            render: (fullName, item) => (
                <Box>
                    <Text fontWeight="medium">{fullName}</Text>
                    {item.email && <Text fontSize="xs" color="fg.muted">{item.email}</Text>}
                </Box>
            ),
        },
        {
            key: 'phone',
            label: 'Телефон',
            render: (phone) => phone || '—',
        },
        {
            key: 'status',
            label: 'Статус',
            render: (status, item) => (
                <Badge
                    colorPalette={getStatusColor(status)}
                    variant="subtle"
                    px={2}
                    py={1}
                    borderRadius="md"
                    fontSize="xs"
                    fontWeight="semibold"
                >
                    {item.status_label}
                </Badge>
            ),
        },
        {
            key: 'region',
            label: 'Регион',
            render: (region) => region?.name || '—',
        },
        {
            key: 'companies_count',
            label: 'Компаний',
            render: (count) => <Badge colorPalette="blue">{count || 0}</Badge>,
        },
        {
            key: 'is_admin',
            label: 'Админ',
            render: (isAdmin) => (
                <Badge colorPalette={isAdmin ? 'green' : 'gray'}>
                    {isAdmin ? 'Да' : 'Нет'}
                </Badge>
            ),
        },
        {
            key: 'created_at',
            label: 'Создан',
            sortable: true,
            render: (_, row) => (
                <Text fontSize="sm" color="gray.600">
                    {row.created_at ? new Date(row.created_at).toLocaleString('ru-RU') : '—'}
                </Text>
            ),
        },
        createActionsColumn('admin.users', openDeleteDialog),
    ];

    return (
        <>
            <PageHeader
                title="Пользователи"
                description="Управление пользователями системы"
                actions={
                    <Button colorPalette="blue" onClick={() => router.visit(route('admin.users.create'))}>
                        <LuPlus /> Создать пользователя
                    </Button>
                }
            />

            {/* Фильтр по статусам */}
            <HStack gap={2} mb={4} flexWrap="wrap">
                <Button
                    size="sm"
                    variant={!filters.status ? 'solid' : 'outline'}
                    colorPalette={!filters.status ? 'blue' : 'gray'}
                    onClick={() => handleStatusFilter('')}
                >
                    Все ({statusCounts?.all || 0})
                </Button>
                {statuses?.map((status) => (
                    <Button
                        key={status.value}
                        size="sm"
                        variant={filters.status === status.value ? 'solid' : 'outline'}
                        colorPalette={filters.status === status.value ? getStatusColor(status.value) : 'gray'}
                        onClick={() => handleStatusFilter(status.value)}
                    >
                        {status.label} ({statusCounts?.[status.value] || 0})
                    </Button>
                ))}
            </HStack>

            <Box mb={4}>
                <SearchInput
                    value={searchQuery}
                    onChange={handleSearch}
                    placeholder="Поиск по имени, email, телефону..."
                />
            </Box>

            <DataTable
                data={users.data}
                columns={columns}
                pagination={users}
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
                title="Удалить пользователя?"
                description={`Вы уверены, что хотите удалить пользователя "${entityToDelete?.name}"? Все связанные данные (компании, адреса) также будут удалены.`}
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
