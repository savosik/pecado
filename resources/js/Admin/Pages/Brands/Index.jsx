import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, HStack, Badge, Image, Text, Button } from '@chakra-ui/react';
import { LuPlus } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';

export default function Index({ brands, filters }) {
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
    } = useResourceIndex('admin.brands', filters, {
        entityLabel: 'Бренд',
    });

    const columns = [
        {
            key: 'id',
            label: 'ID',
            sortable: true,
            width: '80px',
        },
        {
            key: 'logo',
            label: 'Логотип',
            render: (_, brand) => (
                brand?.media && brand.media?.length > 0 ? (
                    <Image
                        src={brand.media[0].original_url}
                        alt={brand.name}
                        w="40px"
                        h="40px"
                        objectFit="cover"
                        borderRadius="md"
                    />
                ) : (
                    <Box
                        w="40px"
                        h="40px"
                        bg="bg.muted"
                        borderRadius="md"
                        display="flex"
                        alignItems="center"
                        justifyContent="center"
                    >
                        <Text fontSize="xs" color="fg.muted">—</Text>
                    </Box>
                )
            ),
        },
        {
            key: 'name',
            label: 'Название',
            sortable: true,
            render: (_, brand) => (
                <Box>
                    <Text fontWeight="medium">{brand.name}</Text>
                    {brand.external_id && (
                        <Text fontSize="sm" color="fg.muted">ID: {brand.external_id}</Text>
                    )}
                </Box>
            ),
        },
        {
            key: 'parent',
            label: 'Родительский бренд',
            render: (_, brand) => brand?.parent?.name || '—',
        },
        {
            key: 'tags',
            label: 'Теги',
            render: (_, brand) => (
                <HStack gap={1} flexWrap="wrap">
                    {brand.tags?.slice(0, 3).map((tag) => (
                        <Badge key={tag.id} size="xs" colorPalette="purple" variant="solid">
                            {tag.name?.ru || tag.name || ''}
                        </Badge>
                    ))}
                    {brand.tags?.length > 3 && (
                        <Badge size="xs" colorPalette="gray" variant="outline">
                            +{brand.tags.length - 3}
                        </Badge>
                    )}
                </HStack>
            ),
        },
        {
            key: 'created_at',
            label: 'Создан',
            sortable: true,
            render: (_, brand) => new Date(brand.created_at).toLocaleDateString('ru-RU'),
        },
        createActionsColumn('admin.brands', openDeleteDialog),
    ];

    return (
        <>
            <PageHeader
                title="Бренды"
                description="Управление брендами и производителями"
                actions={
                    <Button
                        colorPalette="blue"
                        onClick={() => router.visit(route('admin.brands.create'))}
                    >
                        <LuPlus /> Создать бренд
                    </Button>
                }
            />

            <Box mb={4}>
                <SearchInput
                    value={searchQuery}
                    onChange={handleSearch}
                    placeholder="Поиск по названию..."
                />
            </Box>

            <DataTable
                data={brands.data}
                columns={columns}
                pagination={brands}
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
                title="Удалить бренд?"
                description={`Вы уверены, что хотите удалить бренд "${entityToDelete?.name}"? Это действие нельзя отменить.`}
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
