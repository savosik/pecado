import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput } from '@/Admin/Components';
import { Box, Text, Badge } from '@chakra-ui/react';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';

export default function Index({ agreements, filters }) {
    const {
        searchQuery,
        handleSearch,
        handleSort,
        handlePerPageChange,
    } = useResourceIndex('admin.agreements', filters, {
        entityLabel: 'Соглашение',
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
                    <Text fontWeight="medium">{name || '—'}</Text>
                    {item.uuid && <Text fontSize="xs" color="fg.muted">UUID: {item.uuid}</Text>}
                </Box>
            ),
        },
        {
            key: 'partner',
            label: 'Партнёр',
            render: (_, row) => (
                <Box>
                    <Text fontWeight="medium">{row.user?.name || '—'}</Text>
                    {row.user?.email && <Text fontSize="xs" color="fg.muted">{row.user.email}</Text>}
                </Box>
            ),
        },
        {
            key: 'discounts_count',
            label: 'Кастомных скидок',
            render: (count) => (
                <Badge colorPalette="blue">{count || 0}</Badge>
            ),
        },
        {
            key: 'is_active',
            label: 'Активно',
            sortable: true,
            render: (isActive) => (
                <Badge colorPalette={isActive ? 'green' : 'gray'}>
                    {isActive ? 'Да' : 'Нет'}
                </Badge>
            ),
        },
        {
            key: 'starts_at',
            label: 'Начало',
            sortable: true,
            render: (_, row) => (
                <Text fontSize="sm" color="gray.600">
                    {row.starts_at ? new Date(row.starts_at).toLocaleString('ru-RU') : '—'}
                </Text>
            ),
        },
        {
            key: 'ends_at',
            label: 'Окончание',
            sortable: true,
            render: (_, row) => (
                <Text fontSize="sm" color="gray.600">
                    {row.ends_at ? new Date(row.ends_at).toLocaleString('ru-RU') : '—'}
                </Text>
            ),
        },
        {
            key: 'created_at',
            label: 'Создано',
            sortable: true,
            render: (_, row) => (
                <Text fontSize="sm" color="gray.600">
                    {row.created_at ? new Date(row.created_at).toLocaleString('ru-RU') : '—'}
                </Text>
            ),
        },
        {
            key: 'actions',
            label: '',
            width: '160px',
            render: (_, row) => (
                <Box display="flex" gap={4}>
                    <Text 
                        as="button" 
                        color="blue.500" 
                        fontSize="sm" 
                        onClick={() => router.visit(route('admin.agreements.show', row.id))}
                    >
                        Просмотр
                    </Text>
                    <Text 
                        as="button" 
                        color="orange.500" 
                        fontSize="sm" 
                        onClick={() => router.visit(route('admin.agreements.edit', row.id))}
                    >
                        Редакт.
                    </Text>
                </Box>
            ),
        }
    ];

    return (
        <>
            <PageHeader
                title="Индивидуальные соглашения"
                description="Индивидуальные соглашения и их кастомные скидки для партнёров."
                createUrl={route('admin.agreements.create')}
            />

            <Box mb={4} display="flex" gap={4} alignItems="center">
                <Box flex="1">
                    <SearchInput
                        value={searchQuery}
                        onChange={handleSearch}
                        placeholder="Поиск по названию или UUID..."
                    />
                </Box>
                <Box width="200px">
                    <select
                        style={{ width: '100%', padding: '8px', border: '1px solid #e2e8f0', borderRadius: '6px' }}
                        value={filters.is_active ?? ''}
                        onChange={(e) => {
                            router.get(
                                route(route().current()),
                                { ...filters, is_active: e.target.value, page: 1 },
                                { preserveState: true, replace: true }
                            );
                        }}
                    >
                        <option value="">Все статусы</option>
                        <option value="1">Активные</option>
                        <option value="0">Неактивные</option>
                    </select>
                </Box>
            </Box>

            <DataTable
                data={agreements.data}
                columns={columns}
                pagination={agreements}
                onSort={handleSort}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                perPage={filters.per_page}
                onPerPageChange={handlePerPageChange}
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
