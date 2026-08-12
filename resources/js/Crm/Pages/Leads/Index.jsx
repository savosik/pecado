import { useMemo, useState } from 'react';
import axios from 'axios';
import { Head, router } from '@inertiajs/react';
import { Box, Card, HStack, Text, VStack } from '@chakra-ui/react';
import { DndContext, PointerSensor, closestCorners, useSensor, useSensors } from '@dnd-kit/core';
import { SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { LuPlus, LuSlidersHorizontal } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { SearchInput } from '@/Admin/Components/SearchInput';
import { Button } from '@/components/ui/button';
import ScopeToggle from '@/Crm/Components/ScopeToggle';
import { toastError, toastSuccess } from '@/utils/toast';
import LeadCard from './components/LeadCard';
import FunnelPanel from './components/FunnelPanel';
import LeadDialog from './components/LeadDialog';
import StagesDialog from './components/StagesDialog';

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
    funnel,
    filters = {},
    canSeeDepartment = false,
    canCreate = false,
    canEdit = false,
    canManageStages = false,
}) {
    const [leads, setLeads] = useState(initialLeads);
    const [dialogLead, setDialogLead] = useState(null);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [stagesOpen, setStagesOpen] = useState(false);

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

    const onDragEnd = async ({ active, over }) => {
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

    const search = (value) => router.get(
        route('crm.leads.index'),
        { ...filters, search: value || undefined },
        { preserveState: true, replace: true },
    );

    return (
        <CrmLayout breadcrumbs={[{ label: 'Лиды' }]}>
            <Head title="Лиды" />

            <PageHeader
                title="Лиды"
                description="Потенциальные клиенты до появления партнёра в 1С"
                actions={(
                    <HStack gap={2}>
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

                <FunnelPanel funnel={funnel} />

                {stages.length === 0 ? (
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
                    <DndContext sensors={sensors} collisionDetection={closestCorners} onDragEnd={onDragEnd}>
                        {/* Колонок может быть много — доска прокручивается
                            горизонтально, а не сжимает карточки до нечитаемости. */}
                        <HStack align="start" gap={3} overflowX="auto" pb={2}>
                            {stages.map((stage) => (
                                <Box key={stage.id} minW="240px" maxW="240px">
                                    <Card.Root size="sm" bg="bg.subtle">
                                        <Card.Body p={2}>
                                            <HStack justify="space-between" mb={2}>
                                                <Text fontSize="xs" fontWeight="700">{stage.name}</Text>
                                                <Text fontSize="xs" color="fg.muted">
                                                    {byStage[stage.id]?.length ?? 0}
                                                </Text>
                                            </HStack>

                                            <SortableContext
                                                id={`stage-${stage.id}`}
                                                items={(byStage[stage.id] ?? []).map((lead) => lead.id)}
                                                strategy={verticalListSortingStrategy}
                                            >
                                                <VStack align="stretch" gap={2} minH="60px">
                                                    {(byStage[stage.id] ?? []).map((lead) => (
                                                        <LeadCard
                                                            key={lead.id}
                                                            lead={lead}
                                                            draggable={canEdit}
                                                            onOpen={(item) => { setDialogLead(item); setDialogOpen(true); }}
                                                        />
                                                    ))}
                                                </VStack>
                                            </SortableContext>
                                        </Card.Body>
                                    </Card.Root>
                                </Box>
                            ))}
                        </HStack>
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
                canEdit={canEdit}
                onClose={() => setDialogOpen(false)}
                onSaved={() => {
                    setDialogOpen(false);
                    toastSuccess('Лид сохранён.');
                    router.reload();
                }}
            />
        </CrmLayout>
    );
}
