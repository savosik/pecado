import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, Text, Badge, HStack } from '@chakra-ui/react';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';

const locationLabels = {
    header: 'Хедер',
    footer: 'Футер',
    both: 'Везде',
};

const locationColors = {
    header: 'blue',
    footer: 'purple',
    both: 'green',
};

const footerGroupLabels = {
    company: 'О компании',
    buyers: 'Покупателям',
};

export default function Index({ menuItems, filters }) {
    const {
        searchQuery,
        handleSearch,
        handleSort,
        deleteDialogOpen,
        openDeleteDialog,
        confirmDelete,
        closeDeleteDialog,
    } = useResourceIndex('admin.menu-items', filters, {
        entityLabel: 'Пункт меню',
    });

    const columns = [
        {
            key: 'id',
            label: 'ID',
            sortable: true,
            render: (_, row) => <Box fontFamily="mono" fontSize="sm">{row.id}</Box>,
        },
        {
            key: 'title',
            label: 'Название',
            sortable: true,
            render: (_, row) => (
                <HStack gap={2}>
                    <Text fontWeight="semibold">{row.title}</Text>
                    {row.badge_text && (
                        <Badge
                            size="xs"
                            variant="solid"
                            bg={row.badge_color || '#e53e3e'}
                            color="white"
                            borderRadius="full"
                            px={2}
                        >
                            {row.badge_text}
                        </Badge>
                    )}
                </HStack>
            ),
        },
        {
            key: 'url',
            label: 'URL',
            sortable: true,
            render: (_, row) => <Text fontSize="sm" color="fg.muted">{row.url}</Text>,
        },
        {
            key: 'location',
            label: 'Расположение',
            sortable: true,
            render: (_, row) => (
                <HStack gap={1}>
                    <Badge colorPalette={locationColors[row.location]} variant="subtle">
                        {locationLabels[row.location]}
                    </Badge>
                    {row.footer_group && (
                        <Badge colorPalette="gray" variant="outline" size="xs">
                            {footerGroupLabels[row.footer_group]}
                        </Badge>
                    )}
                </HStack>
            ),
        },
        {
            key: 'sort_order',
            label: 'Порядок',
            sortable: true,
            render: (_, row) => <Text fontSize="sm">{row.sort_order}</Text>,
        },
        {
            key: 'is_published',
            label: 'Статус',
            sortable: true,
            render: (_, row) => (
                <Badge colorPalette={row.is_published ? 'green' : 'gray'} variant="subtle">
                    {row.is_published ? 'Активен' : 'Скрыт'}
                </Badge>
            ),
        },
        createActionsColumn('admin.menu-items', openDeleteDialog, { permissionPrefix: 'menu-items' }),
    ];

    return (
        <>
            <PageHeader
                title="Управление меню"
                description="Пункты меню хедера и футера сайта"
                createPermission="menu-items.create"
                onCreate={() => router.visit(route('admin.menu-items.create'))}
                createLabel="Добавить пункт"
            />

            <Box mb={4}>
                <SearchInput
                    value={searchQuery}
                    onChange={handleSearch}
                    placeholder="Поиск по названию или URL..."
                />
            </Box>

            <DataTable
                data={menuItems.data}
                columns={columns}
                pagination={menuItems}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                onSort={handleSort}
            />

            <ConfirmDialog
                open={deleteDialogOpen}
                onClose={closeDeleteDialog}
                onConfirm={confirmDelete}
                title="Удалить пункт меню?"
                description="Вы уверены, что хотите удалить этот пункт меню? Это действие нельзя отменить."
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
