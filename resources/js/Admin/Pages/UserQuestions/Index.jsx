import { useState } from 'react';
import { Badge, Box, HStack, IconButton, Input, Stack, Text } from '@chakra-ui/react';
import { Head, router, usePage } from '@inertiajs/react';
import { LuFilter, LuPaperclip, LuX } from 'react-icons/lu';
import RowActions from '@/shared/Panel/RowActions';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { DataTable } from '@/Admin/Components/DataTable';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { ConfirmDialog } from '@/Admin/Components/ConfirmDialog';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { toaster } from '@/components/ui/toaster';
import { usePermission } from '@/Admin/hooks/usePermission';

const formatDate = (iso) => {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleString('ru-RU', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch {
        return iso;
    }
};

export default function UserQuestionsIndex({ filters, statuses }) {
    const { questions } = usePage().props;
    const { can } = usePermission();
    const [deleteId, setDeleteId] = useState(null);
    const [showFilters, setShowFilters] = useState(false);
    const [localFilters, setLocalFilters] = useState({
        status: filters?.status || '',
        search: filters?.search || '',
        date_from: filters?.date_from || '',
        date_to: filters?.date_to || '',
    });

    const applyFilters = (next = localFilters) => {
        const params = Object.fromEntries(
            Object.entries(next).filter(([, v]) => v !== '' && v !== null && v !== undefined),
        );
        router.get(route('admin.user-questions.index'), params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const resetFilters = () => {
        const empty = { status: '', search: '', date_from: '', date_to: '' };
        setLocalFilters(empty);
        applyFilters(empty);
    };

    const handleDelete = () => {
        if (!deleteId) return;
        router.delete(route('admin.user-questions.destroy', deleteId), {
            onSuccess: () => toaster.create({ description: 'Вопрос удалён', type: 'success' }),
            onFinish: () => setDeleteId(null),
        });
    };

    const columns = [
        { label: 'ID', key: 'id', sortable: false, render: (v) => `#${v}` },
        {
            label: 'Дата',
            key: 'created_at',
            render: (_, row) => (
                <Text fontSize="sm" color="gray.600">{formatDate(row.created_at)}</Text>
            ),
        },
        {
            label: 'От кого',
            key: 'email',
            render: (_, row) => (
                <Stack gap="0">
                    <Text fontWeight="medium">{row.name || '—'}</Text>
                    <Text fontSize="xs" color="gray.500">{row.email}</Text>
                    {row.is_registered && (
                        <Badge colorPalette="purple" variant="subtle" size="xs" mt="1" w="fit-content">
                            Зарегистрирован
                        </Badge>
                    )}
                </Stack>
            ),
        },
        {
            label: 'Тема',
            key: 'subject',
            render: (_, row) => (
                <Stack gap="0" maxW="400px">
                    <Text fontWeight="medium">{row.subject}</Text>
                    <Text fontSize="xs" color="gray.500" truncate>{row.body_preview}</Text>
                </Stack>
            ),
        },
        {
            label: 'Статус',
            key: 'status',
            render: (_, row) => (
                <Badge colorPalette={row.status_color}>{row.status_label}</Badge>
            ),
        },
        {
            label: 'Файл',
            key: 'has_attachment',
            render: (v) => v ? <LuPaperclip color="#888" /> : <Text color="gray.400">—</Text>,
        },
        {
            key: 'actions',
            label: 'Действия',
            render: (_, row) => (
                <RowActions
                    view={{ href: route('admin.user-questions.show', row.id) }}
                    delete={{ onClick: () => setDeleteId(row.id), permission: 'user-questions.delete' }}
                />
            ),
        },
    ];

    return (
        <AdminLayout>
            <Head title="Вопросы пользователей" />
            <PageHeader title="Вопросы пользователей" />

            <HStack mb="4" gap="2">
                <Input
                    placeholder="Поиск по email, теме, тексту..."
                    value={localFilters.search}
                    onChange={(e) => setLocalFilters({ ...localFilters, search: e.target.value })}
                    onKeyDown={(e) => e.key === 'Enter' && applyFilters()}
                    maxW="400px"
                />
                <Button variant="outline" onClick={() => setShowFilters((v) => !v)}>
                    <LuFilter /> Фильтры
                </Button>
                <Button variant="ghost" onClick={resetFilters}>
                    <LuX /> Сбросить
                </Button>
                <Button colorPalette="pecado" onClick={() => applyFilters()}>Применить</Button>
            </HStack>

            {showFilters && (
                <Box bg="bg" border="1px solid" borderColor="border.muted" borderRadius="md" p="4" mb="4">
                    <HStack gap="3" flexWrap="wrap">
                        <Field label="Статус" maxW="220px">
                            <select
                                value={localFilters.status}
                                onChange={(e) => setLocalFilters({ ...localFilters, status: e.target.value })}
                                style={{ padding: '6px 8px', border: '1px solid #ddd', borderRadius: 4, width: '100%' }}
                            >
                                <option value="">Все</option>
                                {(statuses || []).map((s) => (
                                    <option key={s.value} value={s.value}>{s.label}</option>
                                ))}
                            </select>
                        </Field>
                        <Field label="С даты" maxW="180px">
                            <Input
                                type="date"
                                value={localFilters.date_from}
                                onChange={(e) => setLocalFilters({ ...localFilters, date_from: e.target.value })}
                            />
                        </Field>
                        <Field label="По дату" maxW="180px">
                            <Input
                                type="date"
                                value={localFilters.date_to}
                                onChange={(e) => setLocalFilters({ ...localFilters, date_to: e.target.value })}
                            />
                        </Field>
                    </HStack>
                </Box>
            )}

            <DataTable
                columns={columns}
                data={questions.data}
                pagination={questions}
                searchPlaceholder=""
            />

            <ConfirmDialog
                open={!!deleteId}
                onClose={() => setDeleteId(null)}
                onConfirm={handleDelete}
                title="Удаление вопроса"
                description="Удалить этот вопрос вместе с вложением? Действие нельзя отменить."
            />
        </AdminLayout>
    );
}
