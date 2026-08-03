import { useEffect, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { SearchInput } from '@/Admin/Components/SearchInput';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/Admin/Components/ConfirmDialog';
import TaskDialog from '@/Crm/Components/TaskDialog';
import TaskCloseDialog from '@/Crm/Components/TaskCloseDialog';
import { primeTaskOptions } from '@/Crm/Components/useTaskOptions';
import { usePermission } from '@/shared/Panel/usePermission';
import { LuCheck, LuPencil, LuPlus, LuTrash2, LuUndo2 } from 'react-icons/lu';
import { toastError, toastSuccess } from '@/utils/toast';

const PRESETS = [
    { value: 'mine', label: 'Мне', counter: 'mine' },
    { value: 'authored', label: 'От меня', counter: 'authored' },
    { value: 'overdue', label: 'Просрочено', counter: 'overdue' },
    { value: 'unlinked', label: 'Без привязки', counter: null },
    { value: 'all', label: 'Все', counter: null },
];

const DUE_OPTIONS = [
    { value: '', label: 'Любой срок' },
    { value: 'overdue', label: 'Просрочено' },
    { value: 'today', label: 'Сегодня' },
    { value: 'week', label: 'Ближайшая неделя' },
    { value: 'none', label: 'Без срока' },
];

const selectStyle = {
    padding: '0.5rem',
    borderRadius: '0.375rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '160px',
};

/**
 * Раздел «Задачи»: один список с фильтрами.
 *
 * Пресеты переключают фильтр поверх общего списка, а не открывают отдельные его версии:
 * во вкладках задача без привязки или поставленная третьим лицом просто терялась бы.
 */
export default function Index({ tasks, filters, counters, options, openTaskId }) {
    const { can } = usePermission();
    const [dialogTask, setDialogTask] = useState(null);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [pendingDelete, setPendingDelete] = useState(null);
    const [closingTask, setClosingTask] = useState(null);
    const [busy, setBusy] = useState(false);

    // Справочники уже приехали пропсами — диалог не должен запрашивать их повторно.
    primeTaskOptions(options);

    // Ссылка из ленты клиента ведёт сюда с ?task=ID: открываем карточку сразу.
    useEffect(() => {
        if (!openTaskId) {
            return;
        }

        axios.get(`/crm/tasks/${openTaskId}`)
            .then((res) => {
                setDialogTask(res.data);
                setDialogOpen(true);
            })
            .catch(() => {});
    }, [openTaskId]);

    const apply = (patch) => {
        router.get(route('crm.tasks.index'), { ...filters, ...patch, page: undefined }, {
            preserveState: true,
            replace: true,
        });
    };

    const reload = () => router.reload({ only: ['tasks', 'counters'] });

    // Закрытие идёт через диалог: там спрашивают, что сделано и что дальше.
    // Возврат в работу — действие исправляющее, его переспрашивать незачем.
    const toggleDone = async (task) => {
        if (task.status !== 'done') {
            setClosingTask(task);

            return;
        }

        setBusy(true);
        try {
            await axios.patch(`/crm/tasks/${task.id}`, { status: 'open' });
            reload();
        } catch (e) {
            toastError('Статус не изменён', e?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setBusy(false);
        }
    };

    const remove = async (id) => {
        setBusy(true);
        try {
            await axios.delete(`/crm/tasks/${id}`);
            toastSuccess('Задача удалена');
            reload();
        } catch (e) {
            toastError('Не удалось удалить задачу', e?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setBusy(false);
            setPendingDelete(null);
        }
    };

    const openDialog = (task = null) => {
        setDialogTask(task);
        setDialogOpen(true);
    };

    const columns = [
        {
            key: 'title',
            label: 'Задача',
            render: (_, row) => (
                <VStack align="start" gap={1}>
                    <Text
                        fontSize="sm"
                        fontWeight="600"
                        textDecoration={row.status === 'done' ? 'line-through' : undefined}
                    >
                        {row.title}
                    </Text>
                    {row.priority !== 'normal' && (
                        <Badge colorPalette={row.priority_color} variant="subtle" size="sm">
                            {row.priority_label}
                        </Badge>
                    )}
                </VStack>
            ),
        },
        {
            key: 'entity',
            label: 'Привязка',
            render: (_, row) => (row.entity
                ? (
                    <VStack align="start" gap={0}>
                        <Text fontSize="xs" color="fg.muted">{row.entity.label}</Text>
                        {row.entity.url
                            ? <a href={row.entity.url}><Text fontSize="sm">{row.entity.title}</Text></a>
                            : <Text fontSize="sm">{row.entity.title}</Text>}
                    </VStack>
                )
                : <Text fontSize="sm" color="fg.muted">—</Text>),
        },
        {
            key: 'assignee',
            label: 'Исполнитель',
            render: (_, row) => (
                <VStack align="start" gap={0}>
                    <Text fontSize="sm">{row.assignee?.name}</Text>
                    <Text fontSize="xs" color="fg.muted">от {row.author?.name}</Text>
                </VStack>
            ),
        },
        {
            key: 'due_at',
            label: 'Срок',
            sortable: true,
            render: (_, row) => (row.due_at_label
                ? (
                    <HStack gap={2}>
                        <Text fontSize="sm" color={row.is_overdue ? 'red.500' : undefined}>{row.due_at_label}</Text>
                        {row.is_overdue && <Badge colorPalette="red" variant="solid" size="sm">Просрочена</Badge>}
                    </HStack>
                )
                : <Text fontSize="sm" color="fg.muted">без срока</Text>),
        },
        {
            key: 'status',
            label: 'Статус',
            render: (_, row) => (
                <Badge colorPalette={row.status_color} variant="subtle">{row.status_label}</Badge>
            ),
        },
        {
            key: 'actions',
            label: '',
            render: (_, row) => (
                <HStack gap={1}>
                    {row.can?.update && (
                        <Button
                            size="xs"
                            variant="ghost"
                            colorPalette={row.status === 'done' ? undefined : 'green'}
                            disabled={busy}
                            onClick={() => toggleDone(row)}
                            title={row.status === 'done' ? 'Вернуть в работу' : 'Отметить выполненной'}
                        >
                            {row.status === 'done' ? <LuUndo2 /> : <LuCheck />}
                        </Button>
                    )}
                    <Button size="xs" variant="ghost" onClick={() => openDialog(row)} title="Открыть задачу">
                        <LuPencil />
                    </Button>
                    {row.can?.delete && (
                        <Button
                            size="xs"
                            variant="ghost"
                            colorPalette="red"
                            disabled={busy}
                            onClick={() => setPendingDelete(row.id)}
                            title="Удалить"
                        >
                            <LuTrash2 />
                        </Button>
                    )}
                </HStack>
            ),
        },
    ];

    return (
        <>
            <Head title="CRM — Задачи" />
            <PageHeader
                title="Задачи"
                description="Поручения себе и коллегам: полный список отдела в вашей зоне видимости"
                actions={can('crm-tasks.create')
                    ? <Button size="sm" onClick={() => openDialog(null)}><LuPlus /> Поставить задачу</Button>
                    : null}
            />

            <VStack align="stretch" gap={4}>
                <HStack gap={2} flexWrap="wrap">
                    {PRESETS.map((preset) => (
                        <Button
                            key={preset.value}
                            size="sm"
                            variant={filters.preset === preset.value ? 'solid' : 'outline'}
                            onClick={() => apply({ preset: preset.value })}
                        >
                            {preset.label}
                            {preset.counter && counters?.[preset.counter] > 0 && (
                                <Badge ml={2} colorPalette={preset.counter === 'overdue' ? 'red' : 'gray'} variant="subtle">
                                    {counters[preset.counter]}
                                </Badge>
                            )}
                        </Button>
                    ))}
                </HStack>

                <HStack gap={3} align="center" flexWrap="wrap">
                    <Box flex="1" minW="240px">
                        <SearchInput
                            value={filters.search || ''}
                            onChange={(value) => apply({ search: value || undefined })}
                            placeholder="Поиск по заголовку и описанию..."
                        />
                    </Box>

                    <select
                        value={filters.status || ''}
                        onChange={(e) => apply({ status: e.target.value || undefined })}
                        style={selectStyle}
                    >
                        <option value="">Любой статус</option>
                        {(options?.statuses || []).map((item) => (
                            <option key={item.value} value={item.value}>{item.label}</option>
                        ))}
                    </select>

                    <select
                        value={filters.priority || ''}
                        onChange={(e) => apply({ priority: e.target.value || undefined })}
                        style={selectStyle}
                    >
                        <option value="">Любой приоритет</option>
                        {(options?.priorities || []).map((item) => (
                            <option key={item.value} value={item.value}>{item.label}</option>
                        ))}
                    </select>

                    <select
                        value={filters.assignee_id || ''}
                        onChange={(e) => apply({ assignee_id: e.target.value || undefined })}
                        style={selectStyle}
                    >
                        <option value="">Любой исполнитель</option>
                        {(options?.assignees || []).map((user) => (
                            <option key={user.id} value={user.id}>{user.name}</option>
                        ))}
                    </select>

                    <select
                        value={filters.due || ''}
                        onChange={(e) => apply({ due: e.target.value || undefined })}
                        style={selectStyle}
                    >
                        {DUE_OPTIONS.map((item) => (
                            <option key={item.value} value={item.value}>{item.label}</option>
                        ))}
                    </select>

                    <select
                        value={filters.entity_type || ''}
                        onChange={(e) => apply({ entity_type: e.target.value || undefined })}
                        style={selectStyle}
                    >
                        <option value="">Любая привязка</option>
                        {(options?.entity_types || []).map((item) => (
                            <option key={item.value} value={item.value}>{item.label}</option>
                        ))}
                    </select>
                </HStack>

                <DataTable
                    data={tasks.data}
                    columns={columns}
                    pagination={tasks}
                    sortColumn={filters.sort_by}
                    sortDirection={filters.sort_order}
                    onSort={(column, direction) => apply({ sort_by: column, sort_order: direction })}
                />
            </VStack>

            <TaskDialog
                open={dialogOpen}
                task={dialogTask}
                onClose={() => setDialogOpen(false)}
                onSaved={reload}
            />

            <TaskCloseDialog
                task={closingTask}
                onClose={() => setClosingTask(null)}
                onClosed={reload}
            />

            <ConfirmDialog
                open={pendingDelete !== null}
                onClose={() => setPendingDelete(null)}
                onConfirm={() => remove(pendingDelete)}
                title="Удалить задачу?"
                description="Задача пропадёт из списков и из ленты клиента. Восстановить её сможет только администратор."
                confirmLabel="Удалить"
                cancelLabel="Отмена"
                isLoading={busy}
            />
        </>
    );
}

Index.layout = (page) => <CrmLayout>{page}</CrmLayout>;
