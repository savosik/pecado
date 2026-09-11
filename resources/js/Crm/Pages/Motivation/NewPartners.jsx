import { Head, router } from '@inertiajs/react';
import { Badge, Box, HStack, SimpleGrid, Table, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Alert } from '@/components/ui/alert';
import MetricHint from '@/Crm/Components/MetricHint';
import PartnerActions, { usePartnerDialogs } from './components/PartnerActions';
import { PartnerName } from './components/partnerCells';
import { fmtDay, fmtRub0, plural } from '../Salary/components/format';
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

/**
 * «Мои новые клиенты»: сколько приносят те, кого я поднял, и попадут ли они в премию отдела.
 *
 * Колонка «до зачёта» сопровождается постоянной пометкой: премия отдела начисляется
 * отдельно и на месячный доход работника не влияет. Без неё колонка читается как
 * «премия не положена, пока не дойдёт».
 */
export default function MotivationNewPartners({ month, month_label: monthLabel, manager, scope_options: scopeOptions, can_see_all: canSeeAll, list }) {
    const { dialogs, setTaskFor, setCallFor } = usePartnerDialogs();
    const rows = list?.rows ?? [];
    const summary = list?.summary ?? {};

    const navigate = (changes) => {
        const params = { month, ...changes };
        if (canSeeAll && manager?.id && !('manager' in changes)) params.manager = manager.id;
        Object.keys(params).forEach((k) => { if (params[k] === '' || params[k] === null || params[k] === undefined) delete params[k]; });
        router.get('/crm/motivation/new-partners', params, { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <CrmLayout breadcrumbs={hubBreadcrumbs('clients', 'new')}>
            <Head title="Мои новые клиенты — CRM" />
            <PageHeader
                title={canSeeAll && manager ? `Новые клиенты: ${manager.name}` : 'Мои новые клиенты'}
                description="Сколько приносят те, кого вы подняли, и попадут ли они в премию отдела."
                actions={canSeeAll && (scopeOptions ?? []).length > 0 ? (
                    <select aria-label="Работник" style={selectStyle} value={manager?.id ?? ''} onChange={(e) => navigate({ manager: e.target.value })}>
                        <option value="">Выберите работника…</option>
                        {scopeOptions.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                    </select>
                ) : null}
            />
            <MotivationTabs hub="clients" current="new" />

            <VStack align="stretch" gap={4}>
                {manager === null && (
                    <Alert status="info" title="Карточка работника не привязана">Выберите работника выше или обратитесь к руководителю.</Alert>
                )}

                {list && (
                    <>
                        <SimpleGrid columns={{ base: 1, sm: 3 }} gap={3}>
                            <Stat label="В периоде новизны" value={String(summary.total ?? 0)} />
                            <Stat label={`Вознаграждение П2 за ${monthLabel.toLowerCase()}`} value={fmtRub0(summary.reward_total)} tone="green" hint={`Отгрузки новым партнёрам × ставка ${(summary.rate_p2 * 100).toLocaleString('ru-RU', { maximumFractionDigits: 2 })} %. Порог оплаты не применяется.`} />
                            <Stat label="Засчитано в премию отдела" value={`${summary.qualified ?? 0} из ${summary.total ?? 0}`} hint={`Партнёр засчитывается, если его отгрузки за квартал не меньше ${fmtRub0(summary.threshold)}. Премия отдела считается отдельно и на ваш месячный доход не влияет.`} />
                        </SimpleGrid>

                        {rows.length === 0 ? (
                            <Alert status="info" title="Новых партнёров в этом месяце нет">
                                Новым становится партнёр, купивший впервые или после перерыва в двенадцать месяцев. Кандидаты — на экране «Кого разбудить».
                            </Alert>
                        ) : (
                            <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflowX="auto">
                                <Table.Root size="sm">
                                    <Table.Header>
                                        <Table.Row>
                                            <Table.ColumnHeader>Партнёр</Table.ColumnHeader>
                                            <Table.ColumnHeader>Первая покупка</Table.ColumnHeader>
                                            <Table.ColumnHeader>Повышенная ставка ещё</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="right">Купил в этом месяце</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="right">Ваше вознаграждение</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="right">Купил за квартал</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="right">
                                                <HStack gap={1} justify="flex-end"><Text>До зачёта в премию отдела</Text><MetricHint text={list.hint} /></HStack>
                                            </Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="right">Действия</Table.ColumnHeader>
                                        </Table.Row>
                                    </Table.Header>
                                    <Table.Body>
                                        {rows.map((row) => (
                                            <Table.Row key={row.id}>
                                                <Table.Cell>
                                                    <PartnerName row={{ ...row, in_novelty: false }} />
                                                    {row.history_incomplete && (
                                                        <Badge size="xs" colorPalette="gray" variant="subtle" title="Первая отгрузка приходится на первый месяц доступной истории: перерыв подтвердить нельзя">
                                                            перерыв подтвердить нельзя
                                                        </Badge>
                                                    )}
                                                </Table.Cell>
                                                <Table.Cell><Text fontSize="sm">{row.novelty_started_on ? fmtDay(row.novelty_started_on) : '—'}</Text></Table.Cell>
                                                <Table.Cell>
                                                    <VStack align="stretch" gap={1} minW="110px">
                                                        <Text fontSize="xs" color="fg.muted">{row.months_left} из {row.months_total} {plural(row.months_total, 'месяца', 'месяцев', 'месяцев')}</Text>
                                                        <Box h="6px" bg="bg.muted" borderRadius="full" overflow="hidden">
                                                            <Box h="100%" w={`${(row.months_left / row.months_total) * 100}%`} bg="blue.solid" borderRadius="full" />
                                                        </Box>
                                                    </VStack>
                                                </Table.Cell>
                                                <Table.Cell textAlign="right"><Text fontSize="sm" fontVariantNumeric="tabular-nums">{fmtRub0(row.current_month)}</Text></Table.Cell>
                                                <Table.Cell textAlign="right"><Text fontSize="sm" fontWeight="700" color="green.fg" fontVariantNumeric="tabular-nums">+{fmtRub0(row.reward)}</Text></Table.Cell>
                                                <Table.Cell textAlign="right"><Text fontSize="sm" fontVariantNumeric="tabular-nums">{fmtRub0(row.quarter_amount)}</Text></Table.Cell>
                                                <Table.Cell textAlign="right">
                                                    {row.qualified
                                                        ? <Badge size="xs" colorPalette="green" variant="subtle">засчитан</Badge>
                                                        : <Text fontSize="sm" color="fg.muted" fontVariantNumeric="tabular-nums">ещё {fmtRub0(row.to_qualification)}</Text>}
                                                </Table.Cell>
                                                <Table.Cell textAlign="right"><PartnerActions row={row} onTask={setTaskFor} onCall={setCallFor} /></Table.Cell>
                                            </Table.Row>
                                        ))}
                                    </Table.Body>
                                </Table.Root>
                            </Box>
                        )}
                    </>
                )}
            </VStack>

            {dialogs}
        </CrmLayout>
    );
}

function Stat({ label, value, tone, hint }) {
    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
            <HStack gap={1} fontSize="xs" color="fg.muted">
                <Text>{label}</Text>
                {hint && <MetricHint text={hint} />}
            </HStack>
            <Text fontSize="2xl" fontWeight="800" fontVariantNumeric="tabular-nums" color={tone ? `${tone}.fg` : undefined}>{value}</Text>
        </Box>
    );
}
