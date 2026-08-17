import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput } from '@/Admin/Components';
import { Badge, Box, Text } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';

const STATUS_META = {
    open: { label: 'Открыт', color: 'gray' },
    in_progress: { label: 'Идёт диалог', color: 'blue' },
    resolved: { label: 'Итог согласован', color: 'green' },
    closed: { label: 'Закрыт', color: 'purple' },
};

const TURN_LABELS = {
    site: 'Агент сайта',
    erp: 'Агент 1С',
};

export default function Index({ topics, filters }) {
    const { searchQuery, handleSearch, handleSort } = useResourceIndex('admin.agent-topics', filters, {
        entityLabel: 'Топик',
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
            render: (_, row) => <Text fontWeight="semibold">{row.title}</Text>,
        },
        {
            key: 'status',
            label: 'Статус',
            sortable: true,
            render: (_, row) => {
                const meta = STATUS_META[row.status] ?? { label: row.status, color: 'gray' };
                return <Badge colorPalette={meta.color}>{meta.label}</Badge>;
            },
        },
        {
            key: 'turn',
            label: 'Чей ход',
            render: (_, row) => <Text fontSize="sm">{TURN_LABELS[row.turn] ?? row.turn}</Text>,
        },
        {
            key: 'messages_count',
            label: 'Сообщений',
            render: (_, row) => <Text fontSize="sm">{row.messages_count}</Text>,
        },
        {
            key: 'updated_at',
            label: 'Обновлён',
            sortable: true,
            render: (_, row) => <Text fontSize="sm">{row.updated_at}</Text>,
        },
        {
            key: 'actions',
            label: '',
            render: (_, row) => (
                <Button
                    size="xs"
                    variant="outline"
                    onClick={() => router.visit(route('admin.agent-topics.show', row.id))}
                >
                    Открыть
                </Button>
            ),
        },
    ];

    return (
        <>
            <PageHeader
                title="Диалоги ИИ-агентов"
                createPermission="agent-topics.create"
                onCreate={() => router.visit(route('admin.agent-topics.create'))}
                createLabel="Создать топик"
            />

            <Box mb={4}>
                <SearchInput
                    value={searchQuery}
                    onChange={handleSearch}
                    placeholder="Поиск по названию..."
                />
            </Box>

            <DataTable
                data={topics.data}
                columns={columns}
                pagination={topics}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                onSort={handleSort}
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
