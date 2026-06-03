import { useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog, DeleteAllButton, TrashedFilter } from '@/Admin/Components';
import { Box, Text, Button, Badge, HStack, IconButton } from '@chakra-ui/react';
import { LuPlus, LuTrash2 } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';
import { usePermission } from '@/Admin/hooks/usePermission';
import { toaster } from '@/components/ui/toaster';

export default function Index({ companies, filters, trashedCount }) {
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
    } = useResourceIndex('admin.companies', filters, {
        entityLabel: 'Компания',
    });

    const [forceDeleteId, setForceDeleteId] = useState(null);
    const [forceDeleteAllDialogOpen, setForceDeleteAllDialogOpen] = useState(false);
    const [forceDeleteAllProcessing, setForceDeleteAllProcessing] = useState(false);

    const isTrashed = !!filters?.trashed;

    const toggleTrashed = () => {
        router.get(route('admin.companies.index'), { trashed: isTrashed ? undefined : 1 }, { preserveState: false });
    };

    const confirmForceDeleteAll = () => {
        setForceDeleteAllProcessing(true);
        router.delete(route('admin.bulk-force-delete-all', 'companies'), {
            onSuccess: () => {
                toaster.create({ title: 'Окончательное удаление запущено в фоне', type: 'info' });
                setForceDeleteAllDialogOpen(false);
                setForceDeleteAllProcessing(false);
            },
            onError: () => {
                toaster.create({ title: 'Ошибка при запуске удаления', type: 'error' });
                setForceDeleteAllProcessing(false);
            },
        });
    };

    const handleForceDelete = () => {
        if (forceDeleteId) {
            router.delete(route('admin.companies.force-delete', forceDeleteId), {
                onSuccess: () => {
                    toaster.create({ description: 'Компания окончательно удалена', type: 'success' });
                    setForceDeleteId(null);
                },
            });
        }
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
            label: 'Название',
            sortable: true,
            render: (name, item) => (
                <Box>
                    <Text fontWeight="medium">{name}</Text>
                    {item.legal_name && (
                        <Text fontSize="xs" color="fg.muted">{item.legal_name}</Text>
                    )}
                    {item.deleted_at && (
                        <Badge colorPalette="red" variant="subtle" size="xs" mt={0.5}>
                            Удалена: {item.deleted_at ? new Date(item.deleted_at).toLocaleDateString('ru-RU') : ''}
                        </Badge>
                    )}
                </Box>
            ),
        },
        {
            key: 'user',
            label: 'Пользователь',
            render: (user) => user ? (
                <Text
                    cursor="pointer"
                    color="blue.600"
                    _hover={{ textDecoration: 'underline' }}
                    onClick={() => router.visit(route('admin.users.edit', user.id))}
                >
                    {user.name}
                </Text>
            ) : '—',
        },
        {
            key: 'country',
            label: 'Страна',
            render: (country) => country || '—',
        },
        {
            key: 'tax_id',
            label: 'ИНН',
            render: (taxId) => taxId || '—',
        },
        {
            key: 'bank_accounts_count',
            label: 'Счетов',
            render: (count) => <Badge colorPalette="blue">{count || 0}</Badge>,
        },
        {
            key: 'created_at',
            label: 'Создана',
            sortable: true,
            render: (_, row) => (
                <Text fontSize="sm" color="gray.600">
                    {row.created_at ? new Date(row.created_at).toLocaleString('ru-RU') : '—'}
                </Text>
            ),
        },
        isTrashed
            ? {
                key: 'actions',
                label: 'Действия',
                render: (_, row) => can('companies.delete') ? (
                    <IconButton
                        size="sm"
                        variant="ghost"
                        colorPalette="red"
                        aria-label="Удалить окончательно"
                        title="Удалить окончательно"
                        onClick={() => setForceDeleteId(row.id)}
                    >
                        <LuTrash2 />
                    </IconButton>
                ) : null,
            }
            : createActionsColumn('admin.companies', openDeleteDialog, { permissionPrefix: 'companies' , showView: true}),
    ];

    return (
        <>
            <PageHeader
                title="Компании"
                description="Управление компаниями пользователей"
                actions={
                    <HStack>
                        <TrashedFilter
                            trashed={isTrashed}
                            trashedCount={trashedCount}
                            onToggle={toggleTrashed}
                        />
                        {isTrashed ? (
                            <DeleteAllButton
                                sectionLabel="удалённые компании окончательно"
                                dialogOpen={forceDeleteAllDialogOpen}
                                onOpen={() => setForceDeleteAllDialogOpen(true)}
                                onClose={() => setForceDeleteAllDialogOpen(false)}
                                onConfirm={confirmForceDeleteAll}
                                isLoading={forceDeleteAllProcessing}
                            />
                        ) : (
                            <DeleteAllButton
                                sectionLabel="компании"
                                dialogOpen={deleteAllDialogOpen}
                                onOpen={openDeleteAllDialog}
                                onClose={closeDeleteAllDialog}
                                onConfirm={confirmDeleteAll}
                                isLoading={deleteAllProcessing}
                            />
                        )}
                        {!isTrashed && can('companies.create') && (
                            <Button colorPalette="blue" onClick={() => router.visit(route('admin.companies.create'))}>
                                <LuPlus /> Создать компанию
                            </Button>
                        )}
                    </HStack>
                }
            />

            <Box mb={4}>
                <SearchInput
                    value={searchQuery}
                    onChange={handleSearch}
                    placeholder="Поиск по названию, юр. названию, ИНН..."
                />
            </Box>

            <DataTable
                data={companies.data}
                columns={columns}
                pagination={companies}
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
                title="Удалить компанию?"
                description={`Вы уверены, что хотите удалить компанию "${entityToDelete?.name}"? Все банковские счета компании также будут удалены.`}
            />

            <ConfirmDialog
                open={!!forceDeleteId}
                onClose={() => setForceDeleteId(null)}
                onConfirm={handleForceDelete}
                title="Окончательное удаление компании"
                description="Вы уверены, что хотите окончательно удалить эту компанию? Запись будет удалена безвозвратно."
                confirmLabel="Удалить окончательно"
                colorPalette="red"
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
