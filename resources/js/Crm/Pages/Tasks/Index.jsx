import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import {
    Badge,
    Box,
    HStack,
    NativeSelectField,
    NativeSelectRoot,
    Spinner,
    Text,
    VStack,
} from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { SearchInput } from '@/Admin/Components/SearchInput';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/Admin/Components/ConfirmDialog';
import TaskDialog from '@/Crm/Components/TaskDialog';
import TaskRow from '@/Crm/Components/TaskRow';
import ScopeToggle from '@/Crm/Components/ScopeToggle';
import TaskRecurrenceDialog from '@/Crm/Components/TaskRecurrenceDialog';
import TaskCloseDialog from '@/Crm/Components/TaskCloseDialog';
import { primeTaskOptions } from '@/Crm/Components/useTaskOptions';
import { usePermission } from '@/shared/Panel/usePermission';
import { LuCalendarDays, LuChevronDown, LuChevronRight, LuPlus, LuRepeat } from 'react-icons/lu';
import { toastError, toastSuccess } from '@/utils/toast';

const PRESETS = [
    { value: 'mine', label: 'Мне', counter: 'mine' },
    { value: 'authored', label: 'От меня', counter: 'authored' },
    { value: 'watching', label: 'На контроле', counter: 'watching' },
    { value: 'overdue', label: 'Просрочено', counter: 'overdue' },
    { value: 'unlinked', label: 'Без привязки', counter: null },
    { value: 'completed', label: 'Завершённые', counter: null },
    { value: 'all', label: 'Все', counter: null },
];

const DUE_OPTIONS = [
    { value: '', label: 'Любой срок' },
    { value: 'overdue', label: 'Просрочено' },
    { value: 'today', label: 'Сегодня' },
    { value: 'week', label: 'Ближайшая неделя' },
    { value: 'none', label: 'Без срока' },
];

// Порядок секций фиксированный: сначала то, что горит.
const SECTIONS = [
    { key: 'pinned', label: 'Закреплённые', tone: 'blue' },
    { key: 'overdue', label: 'Просрочено', tone: 'red' },
    { key: 'today', label: 'Сегодня', tone: 'orange' },
    { key: 'tomorrow', label: 'Завтра', tone: undefined },
    { key: 'week', label: 'Эта неделя', tone: undefined },
    { key: 'later', label: 'Позже', tone: undefined },
    { key: 'none', label: 'Без срока', tone: undefined },
    { key: 'closed', label: 'Завершённые', tone: 'gray' },
];

const COLLAPSE_KEY = 'crm-tasks-collapsed-sections';

/**
 * Раздел «Задачи» v2: секции по сроку, бесконечная прокрутка, закрепление.
 *
 * Пресеты переключают фильтр поверх общего списка, а не открывают отдельные его
 * версии: во вкладках задача без привязки или поставленная третьим лицом просто
 * терялась бы. Пагинации нет — порции догружаются при прокрутке.
 */
export default function Index({ tasks, filters, counters, options, openTaskId, canSeeDepartment = false }) {
    const { can } = usePermission();
    const [rows, setRows] = useState(tasks.data);
    const [nextPage, setNextPage] = useState(tasks.current_page + 1);
    const [hasMore, setHasMore] = useState(tasks.current_page < tasks.last_page);
    const [loadingMore, setLoadingMore] = useState(false);
    const [dialogTask, setDialogTask] = useState(null);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [pendingDelete, setPendingDelete] = useState(null);
    const [closingTask, setClosingTask] = useState(null);
    const [busy, setBusy] = useState(false);
    const [recurrenceOpen, setRecurrenceOpen] = useState(false);
    const [collapsed, setCollapsed] = useState(() => readCollapsed());
    const sentinelRef = useRef(null);

    // Справочники уже приехали пропсами — диалог не должен запрашивать их повторно.
    primeTaskOptions(options);

    // Смена фильтров перерисовывает страницу с первой порцией — лента сбрасывается.
    useEffect(() => {
        setRows(tasks.data);
        setNextPage(tasks.current_page + 1);
        setHasMore(tasks.current_page < tasks.last_page);
    }, [tasks]);

    // Ссылка из ленты партнёра ведёт сюда с ?task=ID: открываем карточку сразу.
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

    const loadMore = useCallback(async () => {
        if (loadingMore || !hasMore) {
            return;
        }

        setLoadingMore(true);
        try {
            const res = await axios.get('/crm/tasks/data', {
                params: { ...cleanFilters(filters), page: nextPage },
            });

            // Дубли отбрасываем: данные могли сдвинуться между порциями.
            setRows((prev) => {
                const seen = new Set(prev.map((row) => row.id));

                return [...prev, ...res.data.data.filter((row) => !seen.has(row.id))];
            });
            setNextPage(res.data.current_page + 1);
            setHasMore(res.data.current_page < res.data.last_page);
        } catch {
            toastError('Не удалось догрузить задачи');
        } finally {
            setLoadingMore(false);
        }
    }, [loadingMore, hasMore, nextPage, filters]);

    // Бесконечная прокрутка: часовой в конце списка.
    useEffect(() => {
        const sentinel = sentinelRef.current;

        if (!sentinel || !hasMore) {
            return;
        }

        const observer = new IntersectionObserver(
            (entries) => entries[0].isIntersecting && loadMore(),
            { rootMargin: '400px' },
        );

        observer.observe(sentinel);

        return () => observer.disconnect();
    }, [loadMore, hasMore]);

    const apply = (patch) => {
        router.get(route('crm.tasks.index'), { ...cleanFilters(filters), ...patch, page: undefined }, {
            preserveState: true,
            replace: true,
        });
    };

    const reload = () => router.reload({ only: ['tasks', 'counters'] });

    const patchRow = (updated) => setRows((prev) => prev.map((row) => (row.id === updated.id ? { ...row, ...updated } : row)));

    // Закрытие идёт через диалог: там спрашивают исход и что дальше.
    // Возврат в работу — действие исправляющее, его переспрашивать незачем.
    const toggleDone = async (task) => {
        if (task.status !== 'done' && task.status !== 'canceled') {
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

    const togglePin = async (task) => {
        setBusy(true);
        try {
            const res = task.is_pinned
                ? await axios.delete(`/crm/tasks/${task.id}/pin`)
                : await axios.post(`/crm/tasks/${task.id}/pin`);
            patchRow(res.data);
        } catch (e) {
            toastError('Не удалось изменить закрепление', e?.response?.data?.message || 'Попробуйте ещё раз.');
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

    const toggleSection = (key) => {
        setCollapsed((prev) => {
            const next = prev.includes(key) ? prev.filter((item) => item !== key) : [...prev, key];
            try {
                localStorage.setItem(COLLAPSE_KEY, JSON.stringify(next));
            } catch { /* приватный режим */ }

            return next;
        });
    };

    // Секции считаются из накопленной ленты; закреплённые уходят в свою.
    const grouped = useMemo(() => {
        const buckets = Object.fromEntries(SECTIONS.map((section) => [section.key, []]));

        rows.forEach((row) => {
            const key = row.is_pinned && row.status !== 'done' && row.status !== 'canceled'
                ? 'pinned'
                : (row.due_bucket || 'none');

            (buckets[key] || buckets.none).push(row);
        });

        return buckets;
    }, [rows]);

    const hasActiveFilters = ['status', 'outcome', 'priority', 'assignee_id', 'author_id', 'client_id', 'entity_type', 'due', 'search']
        .some((key) => filters[key]);

    const totalEstimate = (list) => {
        const minutes = list.reduce((sum, row) => sum + (row.estimate_minutes || 0), 0);

        if (!minutes) {
            return null;
        }

        const hours = Math.floor(minutes / 60);
        const rest = minutes % 60;

        return hours ? `~${hours} ч${rest ? ` ${rest} мин` : ''}` : `~${minutes} мин`;
    };

    return (
        <>
            <Head title="CRM — Задачи" />
            <PageHeader
                title="Задачи"
                description="Поручения себе и коллегам: полный список отдела в вашей зоне видимости"
                actions={(
                    <HStack gap={2}>
                        <Button size="sm" variant="outline" onClick={() => router.visit(route('crm.tasks.calendar'))}>
                            <LuCalendarDays /> Календарь
                        </Button>
                        {can('crm-tasks.create') && (
                            <>
                                <Button size="sm" variant="outline" onClick={() => setRecurrenceOpen(true)}>
                                    <LuRepeat /> Повторяющаяся
                                </Button>
                                <Button size="sm" onClick={() => openDialog(null)}><LuPlus /> Поставить задачу</Button>
                            </>
                        )}
                    </HStack>
                )}
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
                    <ScopeToggle
                        section="tasks"
                        scope={filters.scope}
                        available={canSeeDepartment}
                        label="Только мои"
                    />
                </HStack>

                <HStack gap={3} align="center" flexWrap="wrap">
                    <Box flex="1" minW="240px">
                        <SearchInput
                            value={filters.search || ''}
                            onChange={(value) => apply({ search: value || undefined })}
                            placeholder="Поиск по заголовку и описанию..."
                        />
                    </Box>

                    <FilterSelect
                        value={filters.status || ''}
                        onChange={(value) => apply({ status: value || undefined })}
                        placeholder="Любой статус"
                        items={options?.statuses || []}
                    />

                    {filters.preset === 'completed' && (
                        <FilterSelect
                            value={filters.outcome || ''}
                            onChange={(value) => apply({ outcome: value || undefined })}
                            placeholder="Любой исход"
                            items={options?.outcomes || []}
                        />
                    )}

                    <FilterSelect
                        value={filters.priority || ''}
                        onChange={(value) => apply({ priority: value || undefined })}
                        placeholder="Любой приоритет"
                        items={options?.priorities || []}
                    />

                    <FilterSelect
                        value={filters.assignee_id || ''}
                        onChange={(value) => apply({ assignee_id: value || undefined })}
                        placeholder="Любой исполнитель"
                        items={(options?.assignees || []).map((user) => ({ value: user.id, label: user.name }))}
                    />

                    <FilterSelect
                        value={filters.due || ''}
                        onChange={(value) => apply({ due: value || undefined })}
                        items={DUE_OPTIONS}
                    />

                    <FilterSelect
                        value={filters.entity_type || ''}
                        onChange={(value) => apply({ entity_type: value || undefined })}
                        placeholder="Любая привязка"
                        items={options?.entity_types || []}
                    />

                    {hasActiveFilters && (
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => apply({
                                status: undefined,
                                outcome: undefined,
                                priority: undefined,
                                assignee_id: undefined,
                                author_id: undefined,
                                client_id: undefined,
                                entity_type: undefined,
                                due: undefined,
                                search: undefined,
                            })}
                        >
                            Сбросить
                        </Button>
                    )}
                </HStack>

                {rows.length === 0 && (
                    <Box borderWidth="1px" borderRadius="md" p={8} textAlign="center">
                        <Text color="fg.muted">
                            {hasActiveFilters
                                ? 'По этим фильтрам задач нет.'
                                : 'Задач нет — можно выдохнуть или поставить новую.'}
                        </Text>
                    </Box>
                )}

                {SECTIONS.map((section) => {
                    const list = grouped[section.key];

                    if (!list?.length) {
                        return null;
                    }

                    const isCollapsed = collapsed.includes(section.key);
                    const estimate = totalEstimate(list);

                    return (
                        <Box key={section.key}>
                            <HStack
                                gap={2}
                                py={1}
                                cursor="pointer"
                                onClick={() => toggleSection(section.key)}
                                color={section.tone ? `${section.tone}.fg` : undefined}
                            >
                                {isCollapsed ? <LuChevronRight size={14} /> : <LuChevronDown size={14} />}
                                <Text fontSize="sm" fontWeight="700">
                                    {section.label}
                                </Text>
                                <Text fontSize="xs" color="fg.muted">
                                    {list.length}{estimate ? ` · ${estimate}` : ''}
                                </Text>
                            </HStack>

                            {!isCollapsed && (
                                <VStack align="stretch" gap={1}>
                                    {list.map((row) => (
                                        <TaskRow
                                            key={row.id}
                                            task={row}
                                            busy={busy}
                                            onToggleDone={toggleDone}
                                            onOpen={openDialog}
                                            onPin={togglePin}
                                            onDelete={(task) => setPendingDelete(task.id)}
                                        />
                                    ))}
                                </VStack>
                            )}
                        </Box>
                    );
                })}

                {/* Часовой бесконечной прокрутки. */}
                <Box ref={sentinelRef} h="1px" />

                {loadingMore && (
                    <HStack justify="center" py={3}>
                        <Spinner size="sm" />
                        <Text fontSize="sm" color="fg.muted">Загружаем ещё…</Text>
                    </HStack>
                )}

                {!hasMore && rows.length > 0 && (
                    <Text fontSize="xs" color="fg.muted" textAlign="center" py={2}>
                        Это все задачи — {rows.length} шт.
                    </Text>
                )}
            </VStack>

            <TaskRecurrenceDialog
                open={recurrenceOpen}
                onClose={() => setRecurrenceOpen(false)}
                onSaved={reload}
            />

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
                description="Задача пропадёт из списков и из ленты партнёра. Восстановить её сможет только администратор."
                confirmLabel="Удалить"
                cancelLabel="Отмена"
                isLoading={busy}
            />
        </>
    );
}

Index.layout = (page) => <CrmLayout>{page}</CrmLayout>;

function FilterSelect({ value, onChange, items, placeholder }) {
    return (
        <NativeSelectRoot size="sm" width="auto" minW="150px">
            <NativeSelectField value={value} onChange={(e) => onChange(e.target.value)}>
                {placeholder !== undefined && <option value="">{placeholder}</option>}
                {items.map((item) => (
                    <option key={item.value} value={item.value}>{item.label}</option>
                ))}
            </NativeSelectField>
        </NativeSelectRoot>
    );
}

/**
 * В запрос уходят только значимые ключи фильтров: scope и служебные поля
 * пагинации в снимке не нужны.
 */
function cleanFilters(filters) {
    const clean = {};

    ['preset', 'status', 'outcome', 'priority', 'assignee_id', 'author_id', 'client_id', 'entity_type', 'due', 'search', 'sort_by', 'sort_order', 'scope']
        .forEach((key) => {
            if (filters[key] !== null && filters[key] !== undefined && filters[key] !== '') {
                clean[key] = filters[key];
            }
        });

    return clean;
}

function readCollapsed() {
    try {
        const stored = JSON.parse(localStorage.getItem(COLLAPSE_KEY) || '[]');

        return Array.isArray(stored) ? stored : [];
    } catch {
        return [];
    }
}
