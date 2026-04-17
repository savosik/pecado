import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog, DeleteAllButton } from '@/Admin/Components';
import { Box, Text, Button, Badge, HStack, Icon } from '@chakra-ui/react';
import { LuPlus, LuKeyRound } from 'react-icons/lu';
import { Tooltip } from '@/components/ui/tooltip';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';
import { usePermission } from '@/Admin/hooks/usePermission';

const getStatusColor = (status) => {
    const colors = {
        processing: 'yellow',
        active: 'green',
        blocked: 'red',
    };
    return colors[status] || 'gray';
};

export default function Index({ users, filters, statuses, statusCounts, availableRoles }) {
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
        deleteAllDialogOpen,
        deleteAllProcessing,
        openDeleteAllDialog,
        confirmDeleteAll,
        closeDeleteAllDialog,
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
            key: 'name',
            label: 'ФИО',
            sortable: false,
            render: (fullName, item) => (
                <Box>
                    <HStack gap={1}>
                        <Text fontWeight="medium">{fullName}</Text>
                        {item.temporary_password && (
                            <Tooltip
                                content={`Временный пароль: ${item.temporary_password}`}
                                showArrow
                            >
                                <Icon asChild color="orange.500" cursor="help">
                                    <LuKeyRound />
                                </Icon>
                            </Tooltip>
                        )}
                    </HStack>
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
            key: 'roles',
            label: 'Роли',
            render: (_, item) => {
                const roleNames = item.roles?.map(r => r.name) || [];
                if (roleNames.length === 0) return <Text fontSize="sm" color="fg.muted">—</Text>;
                return (
                    <HStack gap={1} flexWrap="wrap">
                        {roleNames.map(name => (
                            <Badge key={name} colorPalette={name === 'super-admin' ? 'red' : 'blue'} fontSize="xs">
                                {name}
                            </Badge>
                        ))}
                    </HStack>
                );
            },
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
        createActionsColumn('admin.users', openDeleteDialog, { permissionPrefix: 'users' }),
    ];

    return (
        <>
            <PageHeader
                title="Пользователи"
                description="Управление пользователями системы"
                actions={
                    <>
                        <DeleteAllButton
                        sectionLabel="пользователей"
                        dialogOpen={deleteAllDialogOpen}
                        onOpen={openDeleteAllDialog}
                        onClose={closeDeleteAllDialog}
                        onConfirm={confirmDeleteAll}
                        isLoading={deleteAllProcessing}
                    />
                        {can('users.create') && (
                    <Button colorPalette="blue" onClick={() => router.visit(route('admin.users.create'))}>
                        <LuPlus /> Создать пользователя
                    </Button>
                    )}
                    </>
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
