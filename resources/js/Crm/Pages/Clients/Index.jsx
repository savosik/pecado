import { useCallback, useState } from 'react';
import axios from 'axios';
import { Head, router } from '@inertiajs/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { Alert } from '@/components/ui/alert';
import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import { LuEye, LuUserX } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { usePermission } from '@/shared/Panel/usePermission';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import PresetsBar from '@/Crm/Components/PresetsBar';
import ScopeToggle from '@/Crm/Components/ScopeToggle';
import TaskDialog from '@/Crm/Components/TaskDialog';
import EmailComposeDialog from '@/Crm/Components/EmailComposeDialog';
import CallDialog from '@/Crm/Components/CallDialog';
import ClientKindDialog from '@/Crm/Components/ClientKindDialog';
import ClientsFilterBar from './components/ClientsFilterBar';
import QuickFilters from './components/QuickFilters';
import TasksCell from './components/TasksCell';
import PlanFactCell from './components/PlanFactCell';
import LastOrderCell from '@/Crm/Components/LastOrderCell';
import LifecycleCell from './components/LifecycleCell';
import ActivityHint from './components/ActivityHint';
import LastVisitHint from '@/Crm/Components/LastVisitHint';
import { EmailCell, PhoneCell } from './components/ContactCells';
import { toastError, toastSuccess } from '@/utils/toast';

export default function Index({
    clients,
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
                <VStack align="start" gap={0}>
                    <HStack gap={2}>
                        <Text fontWeight="semibold">{row.name}</Text>
                        <Text fontFamily="mono" fontSize="10px" color="fg.muted">#{row.id}</Text>
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
            render: (_, row) => <Text fontSize="sm">{row.manager?.name || '—'}</Text>,
        }] : []),
        {
            key: 'actions',
            label: '',
            render: (_, row) => (
                <HStack gap={1}>
                    <Button
                        size="xs"
                        variant="ghost"
                        onClick={() => router.visit(route('crm.clients.show', row.id))}
                        aria-label="Открыть карточку партнёра"
                    >
                        <LuEye />
                    </Button>
                    {canManageKind && (
                        <Button
                            size="xs"
                            variant="ghost"
                            colorPalette="red"
                            onClick={() => setKindFor(row)}
                            aria-label="Это не партнёр — убрать из базы отдела"
                            title="Это не партнёр"
                        >
                            <LuUserX />
                        </Button>
                    )}
                </HStack>
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
                onDelete={deletePreset}
                onSave={savePreset}
            />

            <VStack align="stretch" gap={3} mb={4}>
                <ClientsFilterBar
                    filters={filters}
                    searchQuery={searchQuery}
                    onSearch={handleSearch}
                    onChange={applyFilters}
                    lifecycleOptions={lifecycleOptions}
                    managers={managers}
                    canSeeAll={canSeeAll}
                    canSeeTasks={canSeeTasks}
                    canSeePlans={canSeePlans}
                    uncoveredCount={uncoveredCount}
                />
                <HStack gap={4} wrap="wrap">
                    <ScopeToggle section="clients" scope={filters.scope} available={canSeeAll} />
                    <QuickFilters
                        filters={filters}
                        onApply={applyFilters}
                        onReset={resetFilters}
                        canSeeTasks={canSeeTasks}
                        canSeePlans={canSeePlans}
                        uncoveredCount={uncoveredCount}
                    />
                </HStack>
            </VStack>

            <DataTable
                data={clients.data}
                columns={columns}
                pagination={clients}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                onSort={handleSort}
                perPage={filters.per_page}
                onPerPageChange={(perPage) => applyFilters({ per_page: perPage })}
                emptyMessage="Партнёры не найдены"
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
        </>
    );
}

Index.layout = (page) => <CrmLayout>{page}</CrmLayout>;
