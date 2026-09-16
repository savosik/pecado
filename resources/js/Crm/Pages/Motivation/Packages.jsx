import { Head, router } from '@inertiajs/react';
import { Badge, Box, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Alert } from '@/components/ui/alert';
import MetricHint from '@/Crm/Components/MetricHint';
import PartnerActions, { usePartnerDialogs } from './components/PartnerActions';
import { fmtDay, fmtRub0 } from '../Salary/components/format';
import MotivationTabs from './components/MotivationTabs';
import { hubBreadcrumbs } from './components/hubs';

const selectStyle = {
    padding: '0.45rem 0.6rem',
    borderRadius: '0.5rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '180px',
    background: 'var(--chakra-colors-bg-panel)',
    color: 'var(--chakra-colors-fg)',
};

const MONTH_NAMES = ['январь', 'февраль', 'март', 'апрель', 'май', 'июнь', 'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь'];
const monthLabel = (iso) => {
    const [y, m] = String(iso).split('-').map(Number);
    return `${MONTH_NAMES[(m || 1) - 1]} ${y}`;
};

/**
 * «Выданные»: партнёры, переданные работнику руководителем пакетами, — правила
 * обработки, сроки по каждому и кран. Общий список свободных партнёров работнику
 * не показывается: брать из него он не может, пакеты выдаёт руководитель.
 */
export default function MotivationPackages({ tab_counts: tabCounts = null, manager, scope_options: scopeOptions, can_see_all: canSeeAll, rules, tap, packages = [] }) {
    const { dialogs, setTaskFor, setCallFor } = usePartnerDialogs();

    const navigate = (changes) => {
        const params = { ...changes };
        if (canSeeAll && manager?.id && !('manager' in changes)) params.manager = manager.id;
        Object.keys(params).forEach((k) => { if (params[k] === '' || params[k] === null || params[k] === undefined) delete params[k]; });
        router.get('/crm/motivation/packages', params, { preserveState: true, preserveScroll: true, replace: true });
    };

    const open = packages.filter((p) => p.items.some((it) => it.outcome === 'in_progress'));
    const closed = packages.filter((p) => !p.items.some((it) => it.outcome === 'in_progress'));

    return (
        <CrmLayout breadcrumbs={hubBreadcrumbs('clients', 'packages')}>
            <Head title="Выданные партнёры — CRM" />
            <PageHeader
                title="Выданные партнёры"
                description="Кого руководитель передал вам из общего списка, что с ними нужно сделать и в какой срок."
                actions={canSeeAll && (scopeOptions ?? []).length > 0 ? (
                    <select aria-label="Работник" style={selectStyle} value={manager?.id ?? ''} onChange={(e) => navigate({ manager: e.target.value })}>
                        <option value="">Выберите работника…</option>
                        {scopeOptions.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                    </select>
                ) : undefined}
            />
            <MotivationTabs hub="clients" current="packages" counts={tabCounts} />

            <VStack align="stretch" gap={4}>
                {manager === null && (
                    <Alert status="info" title="Карточка работника не привязана">Выберите работника выше или обратитесь к руководителю.</Alert>
                )}

                {manager && rules && (
                    <>
                        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
                            <Text fontWeight="700" mb={2}>Правила обработки</Text>
                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={3} fontSize="sm">
                                <Rule n="1" title={`Контакт — за ${rules.contact_working_days} рабочих дней`} text="Звонок, письмо или задача по партнёру, записанные в CRM. Засчитывается автоматически, отдельно отмечать не нужно." />
                                <Rule n="2" title={`Первая отгрузка — за ${rules.shipment_days} дней`} text="Партнёр должен купить. Не купил в срок — карточка возвращается в общий список, и её может получить другой работник." />
                                <Rule n="3" title={`Что это даёт: ${rules.rate_p2_percent} % вместо базовой ставки`} text={`Партнёр из общего списка при первой отгрузке становится новым: ${rules.novelty_periods} месяцев с его отгрузок платят ${rules.rate_p2_percent} %, и он идёт в зачёт квартальной премии отдела.`} />
                                <Rule n="4" title="Когда дают пакет" text={`Если вы ${rules.tap_periods} месяца подряд выполняете не меньше ${rules.threshold_percent} % плана. Пакет — до ${rules.package_size} партнёров.`} />
                            </SimpleGrid>
                        </Box>

                        {tap && (
                            <Alert status={tap.blocked ? 'warning' : 'success'} title={tap.blocked ? 'Выдача пакетов вам приостановлена' : 'Пакет можно получить'}>
                                <VStack align="start" gap={1}>
                                    {tap.note && <Text>{tap.note}</Text>}
                                    {(tap.checked ?? []).map((c) => (
                                        <Text key={c.month} fontSize="sm">
                                            {monthLabel(c.month)}: {c.base === null ? 'расчёта по новой схеме нет' : `отгрузки базы ${fmtRub0(c.base)} при пороге ${fmtRub0(c.threshold)}`}
                                            {c.below === true ? ' — ниже порога' : c.below === false ? ' — порог достигнут' : ''}
                                        </Text>
                                    ))}
                                </VStack>
                            </Alert>
                        )}

                        {packages.length === 0 && (
                            <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={6} textAlign="center">
                                <Text fontWeight="600">Пакетов вам ещё не выдавали</Text>
                                <Text fontSize="sm" color="fg.muted" mt={1}>Партнёров из общего списка передаёт руководитель. Как только пакет выдан, он появится здесь со сроками по каждому партнёру.</Text>
                            </Box>
                        )}

                        {open.map((p) => <PackageCard key={p.id} p={p} setTaskFor={setTaskFor} setCallFor={setCallFor} />)}
                        {closed.length > 0 && (
                            <Box>
                                <Text fontSize="sm" fontWeight="600" color="fg.muted" mb={2}>Закрытые пакеты</Text>
                                <VStack align="stretch" gap={3}>
                                    {closed.map((p) => <PackageCard key={p.id} p={p} setTaskFor={setTaskFor} setCallFor={setCallFor} muted />)}
                                </VStack>
                            </Box>
                        )}
                    </>
                )}
            </VStack>

            {dialogs}
        </CrmLayout>
    );
}

function Rule({ n, title, text }) {
    return (
        <HStack align="start" gap={3}>
            <Box flexShrink={0} w="24px" h="24px" borderRadius="full" bg="blue.subtle" color="blue.fg" display="flex" alignItems="center" justifyContent="center" fontSize="xs" fontWeight="700">{n}</Box>
            <VStack align="start" gap={0}>
                <Text fontWeight="600">{title}</Text>
                <Text color="fg.muted">{text}</Text>
            </VStack>
        </HStack>
    );
}

function PackageCard({ p, setTaskFor, setCallFor, muted = false }) {
    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor={p.overdue > 0 && !muted ? 'red.solid' : 'border'} borderRadius="xl" p={4} opacity={muted ? 0.8 : 1}>
            <HStack gap={2} flexWrap="wrap" fontSize="sm" mb={2} align="baseline">
                <Text fontWeight="700">Пакет № {p.id} от {fmtDay(p.issued_on)}</Text>
                <Text color="fg.muted">контакт до {fmtDay(p.contact_due_on)} · отгрузка до {fmtDay(p.shipment_due_on)}</Text>
                <Badge size="xs" variant="subtle" colorPalette={p.overdue > 0 ? 'red' : 'green'}>{p.count} партнёров · контакт в срок {p.contacted_in_time} · отгружено {p.shipped}{p.overdue > 0 ? ` · просрочено ${p.overdue}` : ''}</Badge>
                {p.comment && <HStack gap={1}><Text color="fg.subtle" fontSize="xs">{p.comment}</Text><MetricHint text="Комментарий руководителя при выдаче." /></HStack>}
            </HStack>
            <VStack align="stretch" gap={1}>
                {p.items.map((it) => (
                    <HStack key={it.id} justify="space-between" gap={3} fontSize="sm" flexWrap="wrap" px={2} py={1} borderRadius="md" bg={it.late ? 'red.subtle' : (it.outcome === 'converted' ? 'green.subtle' : 'transparent')}>
                        <HStack gap={2} flexWrap="wrap">
                            <Text fontWeight="600">{it.partner_name}</Text>
                            <Text fontSize="xs" color="fg.muted">{[it.phone, it.email].filter(Boolean).join(' · ')}</Text>
                        </HStack>
                        <HStack gap={3} fontSize="xs" flexWrap="wrap">
                            <Text color={it.first_contact_at ? 'green.fg' : (it.late ? 'red.fg' : 'fg.muted')}>{it.first_contact_at ? `контакт ${fmtDay(it.first_contact_at)}` : `контакта нет — до ${fmtDay(p.contact_due_on)}`}</Text>
                            <Text color={it.first_shipment_at ? 'green.fg' : 'fg.muted'}>{it.first_shipment_at ? `отгрузка ${fmtDay(it.first_shipment_at)}` : `отгрузки нет — до ${fmtDay(p.shipment_due_on)}`}</Text>
                            {it.outcome === 'returned' && <Badge size="xs" variant="subtle" colorPalette="gray">возвращён в список</Badge>}
                            <PartnerActions row={{ id: it.partner_id, name: it.partner_name }} onTask={setTaskFor} onCall={setCallFor} />
                        </HStack>
                    </HStack>
                ))}
            </VStack>
        </Box>
    );
}
