import { useCallback, useEffect, useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { Badge, Box, Grid, HStack, Spinner, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import TaskDialog from '@/Crm/Components/TaskDialog';
import TaskCloseDialog from '@/Crm/Components/TaskCloseDialog';
import ScopeToggle from '@/Crm/Components/ScopeToggle';
import TaskCalendarSubscribeDialog from '@/Crm/Components/TaskCalendarSubscribeDialog';
import { primeTaskOptions } from '@/Crm/Components/useTaskOptions';
import { usePermission } from '@/shared/Panel/usePermission';
import { LuChevronLeft, LuChevronRight, LuLink, LuList, LuPlus } from 'react-icons/lu';
import { toastError, toastSuccess } from '@/utils/toast';

const WEEKDAY_LABELS = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

// Стабильные цвета менеджеров: индекс в отсортированном списке — не хэш,
// чтобы палитра не прыгала между сессиями одного состава отдела.
const MANAGER_PALETTE = ['blue', 'green', 'purple', 'orange', 'teal', 'pink', 'cyan', 'yellow'];

const VIEW_KEY = 'crm-tasks-calendar-view';

/**
 * Календарь задач: месяц и неделя, «мои» или весь отдел с цветами менеджеров.
 *
 * Сетка своя, по образцу платёжного календаря — без сторонних календарных
 * библиотек. Перенос — перетаскиванием плашки на другой день (та же механика
 * postpone, что в диалоге закрытия: счётчик + системный комментарий).
 */
export default function Calendar({ options, canSeeDepartment = false, scope }) {
    const { can } = usePermission();
    const [view, setView] = useState(() => localStorage.getItem(VIEW_KEY) || 'month');
    const [anchor, setAnchor] = useState(() => startOfWeek(new Date()));
    const [tasks, setTasks] = useState([]);
    const [loading, setLoading] = useState(false);
    const [dialogTask, setDialogTask] = useState(null);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [createDue, setCreateDue] = useState('');
    const [closingTask, setClosingTask] = useState(null);
    const [managerFilter, setManagerFilter] = useState([]);
    const [dragTaskId, setDragTaskId] = useState(null);
    const [dropDay, setDropDay] = useState(null);
    const [subscribeOpen, setSubscribeOpen] = useState(false);

    primeTaskOptions(options);

    const range = useMemo(() => (view === 'month' ? monthRange(anchor) : weekRange(anchor)), [view, anchor]);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const res = await axios.get('/crm/tasks/calendar-feed', {
                params: {
                    from: toDateString(range.from),
                    to: toDateString(range.to),
                    scope,
                    manager_ids: managerFilter.length ? managerFilter : undefined,
                },
            });
            setTasks(res.data.data);
        } catch {
            toastError('Не удалось загрузить календарь');
        } finally {
            setLoading(false);
        }
    }, [range, scope, managerFilter]);

    useEffect(() => {
        load();
    }, [load]);

    const setViewPersist = (value) => {
        setView(value);
        try {
            localStorage.setItem(VIEW_KEY, value);
        } catch { /* приватный режим */ }
    };

    const shift = (direction) => {
        const next = new Date(anchor);

        if (view === 'month') {
            next.setMonth(next.getMonth() + direction);
        } else {
            next.setDate(next.getDate() + direction * 7);
        }

        setAnchor(next);
    };

    // Цвет — по составу менеджеров в текущей выборке.
    const managerColors = useMemo(() => {
        const ids = [...new Set(tasks.map((task) => task.assignee?.id).filter(Boolean))].sort((a, b) => a - b);

        return Object.fromEntries(ids.map((id, index) => [id, MANAGER_PALETTE[index % MANAGER_PALETTE.length]]));
    }, [tasks]);

    const managersInView = useMemo(() => {
        const seen = new Map();

        tasks.forEach((task) => {
            if (task.assignee?.id && !seen.has(task.assignee.id)) {
                seen.set(task.assignee.id, task.assignee.name);
            }
        });

        return [...seen.entries()].map(([id, name]) => ({ id, name }));
    }, [tasks]);

    const byDay = useMemo(() => {
        const map = {};

        tasks.forEach((task) => {
            const key = (task.due_at || '').slice(0, 10);

            if (!key) {
                return;
            }

            (map[key] ??= []).push(task);
        });

        return map;
    }, [tasks]);

    const days = useMemo(() => buildDays(range), [range]);

    const openTask = (task) => {
        setDialogTask(task);
        setCreateDue('');
        setDialogOpen(true);
    };

    const createAt = (day) => {
        if (!can('crm-tasks.create')) {
            return;
        }

        setDialogTask(null);
        setCreateDue(`${toDateString(day)}T10:00`);
        setDialogOpen(true);
    };

    // Drag-n-drop: перетаскивание плашки на другой день = перенос срока
    // с сохранением времени. На тач-устройствах перенос — через диалог закрытия.
    const dropOn = async (day) => {
        setDropDay(null);

        const task = tasks.find((item) => item.id === dragTaskId);
        setDragTaskId(null);

        if (!task) {
            return;
        }

        const time = (task.due_at || '').slice(11, 16) || '10:00';
        const target = `${toDateString(day)}T${time}`;

        if (target === task.due_at) {
            return;
        }

        try {
            await axios.post(`/crm/tasks/${task.id}/postpone`, { due_at: target });
            toastSuccess('Срок перенесён', `${task.title} → ${formatDay(day)}`);
            load();
        } catch (e) {
            toastError('Не удалось перенести', e?.response?.data?.message || 'Попробуйте ещё раз.');
        }
    };

    const dayEstimate = (list) => {
        const minutes = list.reduce((sum, task) => sum + (task.estimate_minutes || 0), 0);

        if (!minutes) {
            return null;
        }

        const hours = Math.round((minutes / 60) * 10) / 10;

        return { label: `~${hours} ч`, overloaded: minutes > 480 };
    };

    const title = view === 'month'
        ? capitalize(range.from.toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' }))
        : `${formatDay(range.from)} — ${formatDay(range.to)}`;

    return (
        <>
            <Head title="CRM — Календарь задач" />
            <PageHeader
                title="Календарь задач"
                description="Задачи по срокам: весь отдел или отдельный менеджер"
                actions={(
                    <HStack gap={2}>
                        <Button size="sm" variant="outline" onClick={() => setSubscribeOpen(true)} title="Google/Яндекс Календарь">
                            <LuLink /> Подписка
                        </Button>
                        <Button size="sm" variant="outline" onClick={() => router.visit(route('crm.tasks.index'))}>
                            <LuList /> Списком
                        </Button>
                        {can('crm-tasks.create') && (
                            <Button size="sm" onClick={() => createAt(new Date())}>
                                <LuPlus /> Поставить задачу
                            </Button>
                        )}
                    </HStack>
                )}
            />

            <VStack align="stretch" gap={3}>
                <HStack gap={3} flexWrap="wrap" justify="space-between">
                    <HStack gap={1}>
                        <Button size="sm" variant="ghost" onClick={() => shift(-1)} aria-label="Назад"><LuChevronLeft /></Button>
                        <Button size="sm" variant="outline" onClick={() => setAnchor(startOfWeek(new Date()))}>Сегодня</Button>
                        <Button size="sm" variant="ghost" onClick={() => shift(1)} aria-label="Вперёд"><LuChevronRight /></Button>
                        <Text fontWeight="700" ml={2}>{title}</Text>
                        {loading && <Spinner size="sm" ml={2} />}
                    </HStack>

                    <HStack gap={2}>
                        <Button
                            size="sm"
                            variant={view === 'month' ? 'solid' : 'outline'}
                            onClick={() => setViewPersist('month')}
                        >
                            Месяц
                        </Button>
                        <Button
                            size="sm"
                            variant={view === 'week' ? 'solid' : 'outline'}
                            onClick={() => setViewPersist('week')}
                        >
                            Неделя
                        </Button>
                        <ScopeToggle section="tasks" scope={scope} available={canSeeDepartment} label="Только мои" />
                    </HStack>
                </HStack>

                {/* Легенда менеджеров — только в режиме отдела и когда их больше одного. */}
                {managersInView.length > 1 && (
                    <HStack gap={3} flexWrap="wrap">
                        {managersInView.map((manager) => (
                            <Checkbox
                                key={manager.id}
                                size="sm"
                                checked={!managerFilter.length || managerFilter.includes(manager.id)}
                                onCheckedChange={(e) => {
                                    setManagerFilter((prev) => {
                                        const base = prev.length ? prev : managersInView.map((item) => item.id);

                                        return e.checked
                                            ? [...new Set([...base, manager.id])]
                                            : base.filter((id) => id !== manager.id);
                                    });
                                }}
                            >
                                <HStack gap={1}>
                                    <Box w="10px" h="10px" borderRadius="full" bg={`${managerColors[manager.id] || 'gray'}.solid`} />
                                    <Text fontSize="xs">{manager.name}</Text>
                                </HStack>
                            </Checkbox>
                        ))}
                        {managerFilter.length > 0 && (
                            <Button size="xs" variant="ghost" onClick={() => setManagerFilter([])}>Все менеджеры</Button>
                        )}
                    </HStack>
                )}

                <Grid templateColumns="repeat(7, 1fr)" gap={0} borderWidth="1px" borderRadius="md" overflow="hidden">
                    {WEEKDAY_LABELS.map((label) => (
                        <Box key={label} px={2} py={1} bg="bg.muted" borderBottomWidth="1px">
                            <Text fontSize="xs" fontWeight="600" color="fg.muted">{label}</Text>
                        </Box>
                    ))}

                    {days.map((day) => {
                        const key = toDateString(day);
                        const list = byDay[key] || [];
                        const isToday = key === toDateString(new Date());
                        const inMonth = view !== 'month' || day.getMonth() === range.from.getMonth();
                        const estimate = dayEstimate(list);
                        const visible = view === 'week' ? list : list.slice(0, 4);
                        const hidden = list.length - visible.length;

                        return (
                            <Box
                                key={key}
                                minH={view === 'week' ? '360px' : '110px'}
                                p={1.5}
                                borderWidth="0 1px 1px 0"
                                borderColor="border"
                                bg={dropDay === key ? 'blue.subtle' : (isToday ? 'bg.muted' : undefined)}
                                opacity={inMonth ? 1 : 0.45}
                                onDragOver={(e) => {
                                    e.preventDefault();
                                    setDropDay(key);
                                }}
                                onDragLeave={() => setDropDay((prev) => (prev === key ? null : prev))}
                                onDrop={(e) => {
                                    e.preventDefault();
                                    dropOn(day);
                                }}
                                onDoubleClick={() => createAt(day)}
                            >
                                <HStack justify="space-between" mb={1}>
                                    <Text
                                        fontSize="xs"
                                        fontWeight={isToday ? '800' : '500'}
                                        color={isToday ? 'blue.fg' : 'fg.muted'}
                                    >
                                        {day.getDate()}
                                    </Text>
                                    {estimate && (
                                        <Badge
                                            size="xs"
                                            variant="subtle"
                                            colorPalette={estimate.overloaded ? 'red' : 'gray'}
                                            title={estimate.overloaded ? 'День перегружен: больше 8 часов' : 'Сумма трудоёмкости дня'}
                                        >
                                            {estimate.label}
                                        </Badge>
                                    )}
                                </HStack>

                                <VStack align="stretch" gap={1}>
                                    {visible.map((task) => (
                                        <Box
                                            key={task.id}
                                            px={1.5}
                                            py={0.5}
                                            borderRadius="sm"
                                            bg={`${task.is_overdue ? 'red' : (managerColors[task.assignee?.id] || 'blue')}.subtle`}
                                            borderLeftWidth="3px"
                                            borderLeftColor={`${task.is_overdue ? 'red' : (managerColors[task.assignee?.id] || 'blue')}.solid`}
                                            cursor="pointer"
                                            draggable
                                            onDragStart={() => setDragTaskId(task.id)}
                                            onDragEnd={() => { setDragTaskId(null); setDropDay(null); }}
                                            onClick={(e) => { e.stopPropagation(); openTask(task); }}
                                            title={`${task.title}${task.assignee ? ` — ${task.assignee.name}` : ''}`}
                                        >
                                            <Text fontSize="xs" lineClamp={view === 'week' ? 2 : 1}>
                                                {timeOf(task.due_at)}{task.title}
                                            </Text>
                                        </Box>
                                    ))}

                                    {hidden > 0 && (
                                        <Text
                                            fontSize="xs"
                                            color="fg.muted"
                                            cursor="pointer"
                                            onClick={() => setViewPersist('week') || setAnchor(startOfWeek(day))}
                                        >
                                            ещё {hidden}…
                                        </Text>
                                    )}
                                </VStack>
                            </Box>
                        );
                    })}
                </Grid>

                <Text fontSize="xs" color="fg.muted">
                    Перетащите задачу на другой день, чтобы перенести срок. Двойной клик по дню — новая задача.
                    Задачи без срока в календаре не показываются — они в списке.
                </Text>
            </VStack>

            <TaskDialog
                open={dialogOpen}
                task={dialogTask}
                initialDue={createDue}
                onClose={() => setDialogOpen(false)}
                onSaved={load}
            />

            <TaskCloseDialog
                task={closingTask}
                onClose={() => setClosingTask(null)}
                onClosed={load}
            />

            <TaskCalendarSubscribeDialog
                open={subscribeOpen}
                onClose={() => setSubscribeOpen(false)}
            />
        </>
    );
}

Calendar.layout = (page) => <CrmLayout>{page}</CrmLayout>;

function startOfWeek(date) {
    const result = new Date(date);
    const day = result.getDay() || 7;
    result.setDate(result.getDate() - day + 1);
    result.setHours(0, 0, 0, 0);

    return result;
}

function monthRange(anchor) {
    const first = new Date(anchor.getFullYear(), anchor.getMonth(), 1);
    const last = new Date(anchor.getFullYear(), anchor.getMonth() + 1, 0);

    return { from: first, to: last };
}

function weekRange(anchor) {
    const from = startOfWeek(anchor);
    const to = new Date(from);
    to.setDate(to.getDate() + 6);

    return { from, to };
}

/**
 * Дни сетки: месяц дополняется до полных недель, неделя — как есть.
 */
function buildDays(range) {
    const start = startOfWeek(range.from);
    const days = [];
    const cursor = new Date(start);

    do {
        days.push(new Date(cursor));
        cursor.setDate(cursor.getDate() + 1);
    } while (cursor <= range.to || days.length % 7 !== 0);

    return days;
}

function toDateString(date) {
    const pad = (value) => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function formatDay(date) {
    return date.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
}

function timeOf(dueAt) {
    const time = (dueAt || '').slice(11, 16);

    return time && time !== '00:00' ? `${time} ` : '';
}

function capitalize(text) {
    return text.charAt(0).toUpperCase() + text.slice(1);
}
