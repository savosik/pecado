import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Badge, Box, HStack, Image, Text, VStack } from '@chakra-ui/react';
import { LuBookOpen, LuFileText, LuPlay } from 'react-icons/lu';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';
import { toaster } from '@/components/ui/toaster';

const TYPE_ICONS = { text: LuBookOpen, pdf: LuFileText, video: LuPlay };

const formatDate = (iso) => {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    } catch {
        return iso;
    }
};

/**
 * Реестр инструкций: что и кому показываем в кабинете, CRM и WMS.
 */
export default function Index({ instructions, filters = {}, options }) {
    const [deleteRow, setDeleteRow] = useState(null);

    const apply = (patch) => {
        router.get(route('admin.instructions.index'), { ...filters, ...patch, page: undefined }, {
            preserveState: true,
            replace: true,
        });
    };

    const handleSort = (column, direction) => apply({ sort_by: column, sort_order: direction });

    const confirmDelete = () => {
        if (!deleteRow) return;
        router.delete(route('admin.instructions.destroy', deleteRow.id), {
            preserveScroll: true,
            onSuccess: () => {
                setDeleteRow(null);
                toaster.create({ title: 'Инструкция удалена', type: 'success' });
            },
        });
    };

    const columns = [
        {
            key: 'cover',
            label: '',
            width: '60px',
            render: (_, row) => {
                const Icon = TYPE_ICONS[row.type] || LuBookOpen;
                return row.cover
                    ? <Image src={row.cover} alt={row.title} boxSize="40px" objectFit="cover" borderRadius="md" />
                    : <Box boxSize="40px" bg="bg.muted" borderRadius="md" display="flex" alignItems="center" justifyContent="center" color="fg.muted"><Icon size={18} /></Box>;
            },
        },
        {
            key: 'title',
            label: 'Инструкция',
            sortable: true,
            render: (_, row) => (
                <VStack align="start" gap={0.5}>
                    <Text fontWeight="semibold">{row.title}</Text>
                    {row.short_description && <Text fontSize="sm" color="fg.muted" lineClamp={1}>{row.short_description}</Text>}
                </VStack>
            ),
        },
        {
            key: 'type',
            label: 'Формат',
            sortable: true,
            render: (_, row) => <Badge colorPalette={row.type_color} variant="subtle">{row.type_label}</Badge>,
        },
        {
            key: 'audiences',
            label: 'Кому',
            render: (_, row) => (
                <HStack gap={1} flexWrap="wrap">
                    {row.audience_labels.length > 0
                        ? row.audience_labels.map((a) => <Badge key={a.value} size="sm" colorPalette={a.color}>{a.label}</Badge>)
                        : <Text fontSize="sm" color="red.500">никому</Text>}
                </HStack>
            ),
        },
        {
            key: 'is_published',
            label: 'Статус',
            render: (_, row) => (
                <Badge colorPalette={row.is_published ? 'green' : 'gray'} variant="subtle">
                    {row.is_published ? 'Опубликована' : 'Скрыта'}
                </Badge>
            ),
        },
        {
            key: 'updated_at',
            label: 'Обновлена',
            sortable: true,
            render: (_, row) => (
                <VStack align="start" gap={0}>
                    <Text fontSize="sm">{formatDate(row.updated_at)}</Text>
                    <Text fontSize="xs" color="fg.muted">создана {formatDate(row.created_at)}</Text>
                </VStack>
            ),
        },
        createActionsColumn('admin.instructions', (row) => setDeleteRow(row), { permissionPrefix: 'instructions' }),
    ];

    return (
        <>
            <Head title="Инструкции" />
            <PageHeader
                title="Инструкции"
                description="Тексты, PDF и видео для клиентов, менеджеров и склада"
                createPermission="instructions.create"
                onCreate={() => router.visit(route('admin.instructions.create'))}
                createLabel="Новая инструкция"
            />

            <HStack mb={4} gap={3} flexWrap="wrap">
                <Box flex="1" minW="240px">
                    <SearchInput
                        value={filters.search || ''}
                        onChange={(value) => apply({ search: value || undefined })}
                        placeholder="Поиск по заголовку и описанию…"
                    />
                </Box>
                <NativeSelectRoot size="sm" width="200px">
                    <NativeSelectField value={filters.audience || ''} onChange={(e) => apply({ audience: e.target.value || undefined })}>
                        <option value="">Все аудитории</option>
                        {options.audiences.map((a) => <option key={a.value} value={a.value}>{a.label}</option>)}
                    </NativeSelectField>
                </NativeSelectRoot>
                <NativeSelectRoot size="sm" width="160px">
                    <NativeSelectField value={filters.type || ''} onChange={(e) => apply({ type: e.target.value || undefined })}>
                        <option value="">Все форматы</option>
                        {options.types.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                    </NativeSelectField>
                </NativeSelectRoot>
            </HStack>

            <DataTable
                data={instructions.data}
                columns={columns}
                pagination={instructions}
                sortColumn={filters.sort_by || 'updated_at'}
                sortDirection={filters.sort_order || 'desc'}
                onSort={handleSort}
                emptyMessage="Инструкций пока нет — создайте первую"
            />

            <ConfirmDialog
                open={deleteRow !== null}
                onClose={() => setDeleteRow(null)}
                onConfirm={confirmDelete}
                title="Удалить инструкцию?"
                description={deleteRow ? `«${deleteRow.title}» исчезнет из всех разделов вместе с файлами.` : ''}
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
