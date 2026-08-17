import { useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { Head, router } from '@inertiajs/react';
import { Box, Card, HStack, Text, VStack } from '@chakra-ui/react';
import {
    DndContext,
    DragOverlay,
    PointerSensor,
    closestCorners,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import { LuColumns3, LuPlus, LuSlidersHorizontal, LuTable } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { SearchInput } from '@/Admin/Components/SearchInput';
import { Button } from '@/components/ui/button';
import ScopeToggle from '@/Crm/Components/ScopeToggle';
import TaskDialog from '@/Crm/Components/TaskDialog';
import { toastError, toastSuccess } from '@/utils/toast';
import LeadCard, { LeadCardView } from './components/LeadCard';
import StageColumn from './components/StageColumn';
import FunnelPanel from './components/FunnelPanel';
import LeadDialog from './components/LeadDialog';
import LeadsTable from './components/LeadsTable';
import LeadsFilterBar from './components/LeadsFilterBar';
import BulkActionDialog from './components/BulkActionDialog';
import StagesDialog from './components/StagesDialog';

/** Где запоминается выбранный режим — по образцу липкого scope. */
const VIEW_STORAGE_KEY = 'crm.leads.view';

/**
 * Доска лидов.
 *
 * Перетаскивание — `@dnd-kit`, а не самописный HTML5-drag из админского
 * канбана: там колонки захардкожены в контроллере, а здесь они
 * пользовательские. Оптимистичное обновление с откатом при ошибке —
 * приём оттуда: задержка сети не должна выглядеть как «карточка
 * не перетащилась».
 */
export default function Index({
    stages = [],
    leads: initialLeads = [],
    rows = null,
    funnel,
    filters = {},
    managers = [],
    sources = [],
    currentManagerId = null,
    openLeadId = null,
    staleDays = 14,
    canSeeDepartment = false,
    canCreate = false,
    canEdit = false,
    canDelete = false,
    canManageStages = false,
}) {
    const [leads, setLeads] = useState(initialLeads);
    const [dialogLead, setDialogLead] = useState(null);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [stagesOpen, setStagesOpen] = useState(false);
    const [activeLead, setActiveLead] = useState(null);
    const [bulkIds, setBulkIds] = useState([]);
    const [bulkAction, setBulkAction] = useState(null);
    const [taskFor, setTaskFor] = useState(null);
    const [openTaskId, setOpenTaskId] = useState(null);

    const isTable = filters.view === 'table';

    // Пропсы — единственная правда о списке: после router.reload() локальный
    // стейт иначе остался бы со старыми карточками.
    useEffect(() => { setLeads(initialLeads); }, [initialLeads]);

    // Ссылка вида /crm/leads?lead=42 из ленты и списка задач открывает карточку сразу.
    useEffect(() => {
        if (! openLeadId) return;

        const target = initialLeads.find((item) => item.id === openLeadId);

        if (target) {
            setDialogLead(target);
            setDialogOpen(true);
        }
    }, [openLeadId, initialLeads]);

    // Перетаскивание начинается после небольшого сдвига: иначе обычный клик
    // по карточке считался бы началом драга и она не открывалась бы.
    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 6 } }));

    const byStage = useMemo(() => {
        const map = {};

        stages.forEach((stage) => { map[stage.id] = []; });
        leads.forEach((lead) => {
            if (map[lead.stage_id]) map[lead.stage_id].push(lead);
        });

        return map;
    }, [stages, leads]);

    const onDragStart = ({ active }) => {
        setActiveLead(leads.find((item) => item.id === active.id) ?? null);
    };

    const onDragEnd = async ({ active, over }) => {
        setActiveLead(null);

        if (! over) return;

        const lead = leads.find((item) => item.id === active.id);
        // over.id — либо карточка, либо сама колонка: определяем стадию по обоим.
        const overLead = leads.find((item) => item.id === over.id);
        const targetStage = overLead ? overLead.stage_id : Number(String(over.id).replace('stage-', ''));

        if (! lead || ! targetStage || lead.stage_id === targetStage) return;

        const previous = leads;
        setLeads((prev) => prev.map((item) => (item.id === lead.id
            ? { ...item, stage_id: targetStage, days_on_stage: 0 }
            : item)));

        try {
            await axios.post(route('crm.leads.move', lead.id), { stage_id: targetStage });
            // Воронку пересчитывает сервер: считать её в стейте означало бы
            // вторую правду о тех же цифрах.
            router.reload({ only: ['funnel'] });
        } catch {
            setLeads(previous);
            toastError('Не удалось перенести лида.');
        }
    };

    // Закрытие снимает и ?lead= из адреса: иначе следующая же перезагрузка
    // списка снова открыла бы ту же карточку.
    const closeDialog = () => {
        setDialogOpen(false);

        if (openLeadId) {
            const url = new URL(window.location.href);
            url.searchParams.delete('lead');
            window.history.replaceState({}, '', url);
        }
    };

    // Один переход на все фильтры: undefined убирает параметр из адреса,
    // поэтому «сбросить» — это просто передать undefined.
    // page сбрасывается: смена фильтра на 3-й странице иначе показывает
    // пустой список — выборка сузилась, а номер страницы остался.
    const go = (patch) => router.get(
        route('crm.leads.index'),
        { ...filters, page: undefined, ...patch, lead: undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );

    const search = (value) => go({ search: value || undefined });

    const switchView = (next) => {
        // Режим липкий: менеджер, разбирающий базу таблицей, не должен
        // возвращаться на доску после каждого перехода из ленты.
        try {
            window.localStorage.setItem(VIEW_STORAGE_KEY, next);
        } catch {
            // Приватный режим браузера — не повод ломать переключение.
        }

        go({ view: next === 'board' ? undefined : next });
    };

    // Восстановление режима — только когда его не задали явно в адресе.
    useEffect(() => {
        if (filters.view === 'table' || window.location.search.includes('view=')) return;

        try {
            if (window.localStorage.getItem(VIEW_STORAGE_KEY) === 'table') {
                go({ view: 'table' });
            }
        } catch {
            // Хранилище недоступно — остаёмся на доске.
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Действия панели выделения. Сама панель — часть DataTable, здесь только
    // что делать с выбранными идентификаторами.
    const bulkActions = [
        ...(managers.length > 0
            ? [{ label: 'Сменить менеджера', action: (ids) => { setBulkIds(ids); setBulkAction('assign'); } }]
            : []),
        { label: 'Перенести по воронке', action: (ids) => { setBulkIds(ids); setBulkAction('move'); } },
        ...(canDelete
            ? [{
                label: 'Удалить',
                variant: 'outline',
                colorPalette: 'red',
                action: (ids) => { setBulkIds(ids); setBulkAction('delete'); },
            }]
            : []),
    ];

    const applyBulk = async (payload, successMessage) => {
        try {
            const { data } = await axios.post(route('crm.leads.bulk'), payload);

            const skipped = data.requested - data.applied;
            toastSuccess(
                successMessage,
                skipped > 0 ? `Пропущено чужих лидов: ${skipped}.` : undefined,
            );
            router.reload();
        } catch (error) {
            toastError(error.response?.data?.message ?? 'Не удалось выполнить действие.');
        } finally {
            setBulkIds([]);
            setBulkAction(null);
        }
    };

    return (
        <CrmLayout breadcrumbs={[{ label: 'Лиды' }]}>
            <Head title="Лиды" />

            <PageHeader
                title="Лиды"
                description="Потенциальные клиенты до появления партнёра в 1С"
                actions={(
                    <HStack gap={2}>
                        <HStack gap={0} borderWidth="1px" borderRadius="md" overflow="hidden">
                            <Button
                                size="sm"
                                borderRadius={0}
                                variant={isTable ? 'ghost' : 'subtle'}
                                onClick={() => switchView('board')}
                            >
                                <LuColumns3 /> Доска
                            </Button>
                            <Button
                                size="sm"
                                borderRadius={0}
                                variant={isTable ? 'subtle' : 'ghost'}
                                onClick={() => switchView('table')}
                            >
                                <LuTable /> Таблица
                            </Button>
                        </HStack>

                        {canManageStages && (
                            <Button size="sm" variant="outline" onClick={() => setStagesOpen(true)}>
                                <LuSlidersHorizontal /> Настроить воронку
                            </Button>
                        )}
                        {canCreate && (
                            <Button size="sm" onClick={() => { setDialogLead(null); setDialogOpen(true); }}>
                                <LuPlus /> Новый лид
                            </Button>
                        )}
                    </HStack>
                )}
            />

            <VStack align="stretch" gap={4}>
                <HStack gap={3} wrap="wrap">
                    <Box flex="1" minW="260px">
                        <SearchInput
                            value={filters.search ?? ''}
                            onChange={search}
                            placeholder="Имя, организация, телефон, email..."
                        />
                    </Box>
                    <ScopeToggle section="leads" scope={filters.scope} available={canSeeDepartment} />
                </HStack>

                {isTable && (
                    <LeadsFilterBar
                        filters={filters}
                        stages={stages}
                        managers={managers}
                        sources={sources}
                        staleDays={staleDays}
                        onChange={go}
                        onReset={() => go({
                            manager_id: undefined,
                            stage_id: undefined,
                            source: undefined,
                            stale: undefined,
                        })}
                    />
                )}

                <FunnelPanel funnel={funnel} />

                {isTable ? (
                    <LeadsTable
                        rows={rows}
                        staleDays={staleDays}
                        sort={filters.sort}
                        direction={filters.direction}
                        selectable={canEdit}
                        bulkActions={canEdit ? bulkActions : []}
                        onSort={(column, dir) => go({ sort: column, direction: dir })}
                        onOpen={(lead) => { setDialogLead(lead); setDialogOpen(true); }}
                        onCreateTask={(lead) => setTaskFor(lead)}
                        onOpenTask={(taskId) => setOpenTaskId(taskId)}
                    />
                ) : stages.length === 0 ? (
                    <Card.Root>
                        <Card.Body>
                            <VStack align="start" gap={3}>
                                <Text fontSize="sm" color="fg.muted">
                                    {canManageStages
                                        ? 'Воронка пуста — заведите стадии, и доска появится.'
                                        : 'Воронка пуста: руководитель отдела ещё не завёл стадии.'}
                                </Text>
                                {canManageStages && (
                                    <Button size="sm" onClick={() => setStagesOpen(true)}>
                                        <LuSlidersHorizontal /> Настроить воронку
                                    </Button>
                                )}
                            </VStack>
                        </Card.Body>
                    </Card.Root>
                ) : (
                    <DndContext
                        sensors={sensors}
                        collisionDetection={closestCorners}
                        onDragStart={onDragStart}
                        onDragEnd={onDragEnd}
                        onDragCancel={() => setActiveLead(null)}
                    >
                        {/* Колонок может быть много — доска прокручивается
                            горизонтально, а не сжимает карточки до нечитаемости. */}
                        <HStack align="start" gap={3} overflowX="auto" pb={2}>
                            {stages.map((stage) => (
                                <StageColumn key={stage.id} stage={stage} leads={byStage[stage.id] ?? []}>
                                    {(byStage[stage.id] ?? []).map((lead) => (
                                        <LeadCard
                                            key={lead.id}
                                            lead={lead}
                                            draggable={canEdit}
                                            onOpen={(item) => { setDialogLead(item); setDialogOpen(true); }}
                                        />
                                    ))}
                                </StageColumn>
                            ))}
                        </HStack>

                        {/* Оверлей обязателен: доска прокручивается горизонтально,
                            и карточку, которая тащится внутри потока, обрезает
                            overflow — со стороны это выглядит как «не перетаскивается». */}
                        <DragOverlay>
                            {activeLead && <LeadCardView lead={activeLead} dragging />}
                        </DragOverlay>
                    </DndContext>
                )}
            </VStack>

            <StagesDialog
                open={stagesOpen}
                stages={stages}
                onClose={() => setStagesOpen(false)}
                onSaved={() => router.reload({ only: ['stages', 'leads', 'funnel'] })}
            />

            <LeadDialog
                open={dialogOpen}
                lead={dialogLead}
                stages={stages}
                managers={managers}
                currentManagerId={currentManagerId}
                canEdit={canEdit}
                canDelete={canDelete}
                onClose={closeDialog}
                onSaved={() => {
                    closeDialog();
                    toastSuccess('Лид сохранён.');
                    router.reload();
                }}
                onDeleted={closeDialog}
            />

            <BulkActionDialog
                action={bulkAction}
                count={bulkIds.length}
                stages={stages}
                managers={managers}
                onClose={() => { setBulkAction(null); setBulkIds([]); }}
                onApply={({ action, manager_id: managerId, stage_id: stageId }) => applyBulk(
                    { ids: bulkIds, action, manager_id: managerId, stage_id: stageId },
                    action === 'delete' ? 'Лиды удалены.' : 'Изменения применены.',
                )}
            />

            {/* Задача из таблицы — тот же диалог, что в партнёрах и планах. */}
            <TaskDialog
                open={taskFor !== null || openTaskId !== null}
                taskId={openTaskId}
                task={null}
                entity={taskFor ? { type: 'lead', id: taskFor.id, label: 'Лид', title: taskFor.name } : null}
                onClose={() => { setTaskFor(null); setOpenTaskId(null); }}
                onSaved={() => {
                    setTaskFor(null);
                    setOpenTaskId(null);
                    router.reload({ only: ['rows'] });
                }}
            />
        </CrmLayout>
    );
}
