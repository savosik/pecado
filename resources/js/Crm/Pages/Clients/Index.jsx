import { useCallback, useEffect, useState } from 'react';
import axios from 'axios';
import { Head, router } from '@inertiajs/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { Alert } from '@/components/ui/alert';
import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import { LuUserX } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { usePermission } from '@/shared/Panel/usePermission';
import RowActions from '@/shared/Panel/RowActions';
import { useConfirmDelete } from '@/shared/Panel/useConfirmDelete';
import { ConfirmDialog } from '@/shared/Panel/ConfirmDialog';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import PresetsBar from '@/Crm/Components/PresetsBar';
import ScopeToggle from '@/Crm/Components/ScopeToggle';
import TaskDialog from '@/Crm/Components/TaskDialog';
import EmailComposeDialog from '@/Crm/Components/EmailComposeDialog';
import CallDialog from '@/Crm/Components/CallDialog';
import ClientKindDialog from '@/Crm/Components/ClientKindDialog';
import ListScrollFooter from '@/Crm/Components/ListScrollFooter';
import ClientsFilterBar from './components/ClientsFilterBar';
import ClientsSearchBar from './components/ClientsSearchBar';
import LifecycleFunnel from './components/LifecycleFunnel';
import QuickFilters from './components/QuickFilters';
import TasksCell from './components/TasksCell';
import PlanFactCell from './components/PlanFactCell';
import LastOrderCell from '@/Crm/Components/LastOrderCell';
import LifecycleCell from './components/LifecycleCell';
import PartnerAvatar from '@/Crm/Components/PartnerAvatar';
import ActivityHint from './components/ActivityHint';
import LastVisitHint from '@/Crm/Components/LastVisitHint';
import DebtLevelBadge from '@/Crm/Components/DebtLevelBadge';
import { EmailCell, PhoneCell } from './components/ContactCells';
import { toastError, toastSuccess } from '@/utils/toast';

const LS_INFINITE_SCROLL_KEY = 'crm_clients_infinite_scroll';

/**
 * Фон строки по стадии партнёра — светлый оттенок цвета её чипа.
 *
 * Работает всегда, а не только при выбранном чипе: таблица должна читаться
 * как воронка и без отбора — зелёные активные, жёлтые спящие, серые ушедшие.
 * Оттенок берётся самый бледный (50/950), чтобы текст и бейджи в строке не
 * спорили с фоном; при наведении — на шаг темнее.
 *
 * @param {string|undefined} color — colorPalette стадии
 */
function stageRowProps(color) {
    const palette = color || 'gray';

    return {
        bg: `${palette}.50`,
        _dark: { bg: `${palette}.950` },
        _hover: { bg: `${palette}.100`, _dark: { bg: `${palette}.900` } },
    };
}

/**
 * Параметры запроса без пустых значений: axios и так пропускает null,
 * но в адресе догрузки не должно быть ни `search=` ни `lifecycle=null`.
 */
function cleanParams(params) {
    return Object.fromEntries(
        Object.entries(params).filter(([, value]) => value !== null && value !== undefined && value !== ''),
    );
}

export default function Index({
    clients,
    funnel = null,
    managers,
    filters,
    presets = [],
    canSeeAll,
    canSeeTasks = false,
    canSeePlans = false,
    uncoveredCount = null,
    managerProfileLinked,
    lifecycleOptions = [],
}) {
    const { can } = usePermission();
    // Раздел не даёт удалять партнёров (они принадлежат 1С): из хука берём
    // только поиск и сортировку.
    const { searchQuery, handleSearch, handleSort } = useResourceIndex('crm.clients', filters, {
        entityLabel: 'Партнёр',
    });

    // Диалоги монтируются по одному на страницу, а не на строку: пятнадцать
    // копий модалки в DOM — верный способ уронить таблицу на скролле.
    const [taskFor, setTaskFor] = useState(null);
    // Открытие существующей задачи из строки: диалог догрузит её по id сам.
    const [openTaskId, setOpenTaskId] = useState(null);
    const [emailFor, setEmailFor] = useState(null);
    const [callFor, setCallFor] = useState(null);
    const [kindFor, setKindFor] = useState(null);
    const [savedPresets, setSavedPresets] = useState(presets);

    // ─── Бесконечная прокрутка — как в каталоге товаров ───
    // Строки живут в стейте: сервер отдаёт страницу, а при прокрутке к ней
    // дописываются следующие. Новый ответ Inertia (сменили фильтр, стадию,
    // сортировку) сбрасывает накопленное — это уже другой список.
    const [infiniteScroll, setInfiniteScroll] = useState(() => {
        try {
            return localStorage.getItem(LS_INFINITE_SCROLL_KEY) === '1';
        } catch {
            return false;
        }
    });
    const [rows, setRows] = useState(clients.data);
    const [nextPage, setNextPage] = useState(clients.current_page + 1);
    const [hasMore, setHasMore] = useState(clients.current_page < clients.last_page);
    const [loadingMore, setLoadingMore] = useState(false);

    useEffect(() => {
        setRows(clients.data);
        setNextPage(clients.current_page + 1);
        setHasMore(clients.current_page < clients.last_page);
    }, [clients]);

    const toggleInfiniteScroll = useCallback((enabled) => {
        setInfiniteScroll(enabled);
        try {
            if (enabled) {
                localStorage.setItem(LS_INFINITE_SCROLL_KEY, '1');
            } else {
                localStorage.removeItem(LS_INFINITE_SCROLL_KEY);
            }
        } catch {
            // Приватный режим: переключатель работает, но не запоминается.
        }
    }, []);

    const loadMore = useCallback(async () => {
        if (loadingMore || !hasMore) return;

        setLoadingMore(true);
        try {
            const { data } = await axios.get(route('crm.clients.data'), {
                params: { ...cleanParams(filters), page: nextPage },
            });

            // Дубли отбрасываем: между порциями список мог сдвинуться
            // (коллега сменил стадию, приехал партнёр из 1С).
            setRows((prev) => {
                const seen = new Set(prev.map((row) => row.id));

                return [...prev, ...data.data.filter((row) => !seen.has(row.id))];
            });
            setNextPage(data.current_page + 1);
            setHasMore(data.current_page < data.last_page);
        } catch {
            toastError('Не удалось догрузить партнёров');
        } finally {
            setLoadingMore(false);
        }
    }, [loadingMore, hasMore, nextPage, filters]);

    const rowProps = useCallback((row) => stageRowProps(row.lifecycle?.color), []);

    const canEditLifecycle = can('crm-profile.edit');
    const canWriteEmail = can('crm-emails.create');
    const canCreateTask = can('crm-tasks.create');
    const canLogCall = can('crm-calls.create');
    // Состав базы партнёров отдела — дело того, кто за отдел отвечает.
    const canManageKind = can('crm-clients-all.edit');

    const applyFilters = useCallback((patch) => {
        router.get(route('crm.clients.index'), { ...filters, ...patch }, {
            preserveState: true,
            replace: true,
        });
    }, [filters]);

    // Разрез «только мои» сбросу не подлежит: это режим работы, а не отбор.
    const resetFilters = useCallback(() => {
        router.get(route('crm.clients.index'), { per_page: filters.per_page, scope: filters.scope }, {
            preserveState: false,
            replace: true,
        });
    }, [filters.per_page, filters.scope]);

    const savePreset = async (name) => {
        try {
            const { data } = await axios.post(route('crm.clients.presets.store'), {
                name,
                payload: filters,
            });
            setSavedPresets((prev) => [data, ...prev]);
            toastSuccess('Отбор сохранён');
        } catch {
            toastError('Не удалось сохранить отбор');
        }
    };

    const deletePreset = async (id) => {
        try {
            await axios.delete(route('crm.clients.presets.destroy', id));
            setSavedPresets((prev) => prev.filter((preset) => preset.id !== id));
        } catch {
            toastError('Не удалось удалить отбор');
        }
    };

    const presetDelete = useConfirmDelete({
        title: 'Удалить сохранённый отбор?',
        description: (preset) => `Отбор «${preset?.name ?? ''}» исчезнет из панели. Сами партнёры не затрагиваются.`,
        onConfirm: (preset) => deletePreset(preset.id),
    });

    const applyPreset = (preset) => {
        router.get(route('crm.clients.index'), preset.payload || {}, {
            preserveState: false,
            replace: true,
        });
    };

    const columns = [
        {
            key: 'name',
            label: 'Партнёр',
            sortable: true,
            render: (_, row) => (
                <HStack align="start" gap={2}>
                    {/* Аватарка — чтобы строка узнавалась по картинке, а не
                        вычитывалась из десятка похожих «ООО …». */}
                    <PartnerAvatar
                        avatar={row.avatar}
                        name={row.name}
                        size={28}
                        hint={row.avatar?.source === 'ai' ? 'Аватарку нарисовал ИИ — можно заменить в карточке' : null}
                    />
                    <VStack align="start" gap={0}>
                    <HStack gap={2}>
                        <Text fontWeight="semibold">{row.name}</Text>
                        <Text fontFamily="mono" fontSize="10px" color="fg.muted">#{row.id}</Text>
                        {/* Страховой запас (buf-02): партнёр видит заниженные
                            остатки по рисковым товарам. */}
                        {row.stock_buffer_enabled && (
                            <Badge colorPalette="blue" variant="subtle" size="xs">
                                страховой запас
                            </Badge>
                        )}
                        {row.debt && (
                            <DebtLevelBadge debt={row.debt} size="xs" />
                        )}
                    </HStack>
                    {/* Имя из кабинета — только когда партнёр назвал себя иначе,
                        чем записано в карточке 1С. */}
                    {row.personal_name && (
                        <Text fontSize="xs" color="fg.muted">
                            на сайте: {row.personal_name}
                        </Text>
                    )}
                    <LastVisitHint visit={row.last_visit} />
                    <ActivityHint activity={row.activity} />
                    </VStack>
                </HStack>
            ),
        },
        {
            key: 'email',
            label: 'Email',
            sortable: true,
            render: (_, row) => (
                <EmailCell
                    email={row.email}
                    canWrite={canWriteEmail}
                    onCompose={() => setEmailFor(row)}
                />
            ),
        },
        {
            key: 'phone',
            label: 'Телефон',
            render: (_, row) => (
                <PhoneCell
                    phone={row.phone}
                    digits={row.phone_digits}
                    canCall={canLogCall}
                    onCall={() => setCallFor(row)}
                    onCreateTask={() => setTaskFor(row)}
                />
            ),
        },
        ...(lifecycleOptions.length ? [{
            key: 'lifecycle',
            label: 'Стадия',
            render: (_, row) => (
                <LifecycleCell
                    clientId={row.id}
                    lifecycle={row.lifecycle}
                    options={lifecycleOptions}
                    canEdit={canEditLifecycle}
                />
            ),
        }] : []),
        ...(canSeeTasks ? [{
            key: 'next_task_due',
            label: 'Задачи',
            sortable: true,
            render: (_, row) => (
                <TasksCell
                    tasks={row.tasks}
                    onCreate={canCreateTask ? () => setTaskFor(row) : undefined}
                    onOpen={(id) => setOpenTaskId(id)}
                />
            ),
        }] : []),
        {
            key: 'last_order_at',
            label: 'Последний заказ',
            sortable: true,
            render: (_, row) => <LastOrderCell value={row.last_order} />,
        },
        ...(canSeePlans ? [{
            key: 'plan_percent',
            label: 'План / факт',
            sortable: true,
            render: (_, row) => <PlanFactCell value={row.plan_fact} />,
        }] : []),
        {
            key: 'client_status',
            label: 'Статус',
            render: (_, row) => (row.client_status
                ? <Badge colorPalette="gray" variant="subtle">{row.client_status.name}</Badge>
                : <Text fontSize="sm" color="fg.muted">—</Text>),
        },
        ...(canSeeAll ? [{
            key: 'manager',
            label: 'Менеджер',
            render: (_, row) => (row.manager
                ? <Text fontSize="sm">{row.manager.name}</Text>
                : <Text fontSize="sm" color="fg.muted">не закреплён</Text>),
        }] : []),
        {
            key: 'actions',
            label: 'Действия',
            render: (_, row) => (
                <RowActions
                    size="xs"
                    view={{ href: route('crm.clients.show', row.id), label: 'Открыть карточку партнёра' }}
                    extra={[{
                        icon: LuUserX,
                        label: 'Это не партнёр — убрать из базы отдела',
                        colorPalette: 'red',
                        allowed: canManageKind,
                        onClick: () => setKindFor(row),
                    }]}
                />
            ),
        },
    ];

    return (
        <>
            <Head title="CRM — Партнёры" />
            <PageHeader
                title={canSeeAll ? 'Партнёры отдела' : 'Мои партнёры'}
                description={canSeeAll
                    ? 'Все партнёры с закреплённым менеджером'
                    : 'Партнёры, закреплённые за вами в 1С'}
            />

            {!managerProfileLinked && (
                <Box mb={4}>
                    <Alert status="warning" title="Аккаунт не связан с карточкой менеджера">
                        Ваш аккаунт не связан с карточкой персонального менеджера, поэтому список пуст.
                        Обратитесь к администратору — привязка настраивается в разделе «Персональные менеджеры».
                    </Alert>
                </Box>
            )}

            <PresetsBar
                presets={savedPresets}
                onApply={applyPreset}
                onDelete={(id) => presetDelete.request(savedPresets.find((preset) => preset.id === id) ?? { id })}
                onSave={savePreset}
            />

            {/* Сверху вниз: поиск → воронка стадий → уточняющие отборы →
                быстрые чипы по задачам. Раздел читается как воронка, а
                остальные отборы её только сужают. */}
            <VStack align="stretch" gap={3} mb={4}>
                <ClientsSearchBar value={searchQuery} onChange={handleSearch} />

                {funnel && (
                    <LifecycleFunnel
                        funnel={funnel}
                        active={filters.lifecycle || undefined}
                        onSelect={(value) => applyFilters({ lifecycle: value })}
                    />
                )}

                <ClientsFilterBar
                    filters={filters}
                    onChange={applyFilters}
                    managers={managers}
                    canSeeAll={canSeeAll}
                    canSeeTasks={canSeeTasks}
                    canSeePlans={canSeePlans}
                    uncoveredCount={uncoveredCount}
                >
                    <ScopeToggle section="clients" scope={filters.scope} available={canSeeAll} />
                </ClientsFilterBar>

                <QuickFilters
                    filters={filters}
                    onApply={applyFilters}
                    onReset={resetFilters}
                    canSeeTasks={canSeeTasks}
                    canSeePlans={canSeePlans}
                    uncoveredCount={uncoveredCount}
                />
            </VStack>

            <DataTable
                data={rows}
                columns={columns}
                // В режиме прокрутки страницы листает подвал, а не пагинация.
                pagination={infiniteScroll ? null : clients}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                onSort={handleSort}
                perPage={filters.per_page}
                onPerPageChange={(perPage) => applyFilters({ per_page: perPage })}
                emptyMessage="Партнёры не найдены"
                rowProps={rowProps}
                footer={(
                    <ListScrollFooter
                        infinite={infiniteScroll}
                        onToggle={toggleInfiniteScroll}
                        shown={rows.length}
                        total={clients.total}
                        hasMore={hasMore}
                        loadingMore={loadingMore}
                        onLoadMore={loadMore}
                        allLoadedText="Все партнёры загружены"
                    />
                )}
            />

            <TaskDialog
                open={taskFor !== null || openTaskId !== null}
                taskId={openTaskId}
                entity={taskFor ? { type: 'client', id: taskFor.id, label: 'Партнёр', title: taskFor.name } : null}
                onClose={() => { setTaskFor(null); setOpenTaskId(null); }}
                onSaved={() => {
                    setTaskFor(null);
                    setOpenTaskId(null);
                    // Перезагружаем только список: срок ближайшей задачи и счётчик
                    // считаются на сервере, пересобирать их в стейте — вторая правда.
                    router.reload({ only: ['clients', 'uncoveredCount'] });
                }}
            />

            <CallDialog
                open={callFor !== null}
                client={callFor}
                onClose={() => setCallFor(null)}
                onSaved={() => {
                    setCallFor(null);
                    // Звонок мог поставить следующий шаг — колонка задач обязана
                    // это показать сразу, а не после ручного обновления.
                    router.reload({ only: ['clients', 'uncoveredCount'] });
                }}
            />

            <EmailComposeDialog
                open={emailFor !== null}
                entity={emailFor ? { type: 'client', id: emailFor.id } : null}
                defaultTo={emailFor?.email}
                onClose={() => setEmailFor(null)}
            />

            <ClientKindDialog
                open={kindFor !== null}
                client={kindFor}
                onClose={() => setKindFor(null)}
            />

            <ConfirmDialog {...presetDelete.dialogProps} />
        </>
    );
}

Index.layout = (page) => <CrmLayout>{page}</CrmLayout>;
