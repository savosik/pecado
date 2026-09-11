import { Head, Link, router } from '@inertiajs/react';
import { Badge, Box, HStack, SimpleGrid, Table, Text, VStack } from '@chakra-ui/react';
import { LuDownload, LuLock } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import MetricHint from '@/Crm/Components/MetricHint';
import { fmtPercent, fmtRub0, fmtSigned } from '../Salary/components/format';
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

const STATUS_PALETTE = { draft: 'blue', approved: 'green', paid: 'gray' };

/**
 * Сводка отдела за месяц: где мы сейчас и что случится к концу месяца.
 *
 * Рейтинга работников по доходу нет намеренно: сравнение ведётся
 * в показателях выполнения, а не в рублях зарплаты.
 */
export default function MotivationTeam({ month, month_label: monthLabel, months, department, rows, is_current_month: isCurrent }) {
    const changeMonth = (m) => router.get('/crm/motivation/team', { month: m }, { preserveState: true, preserveScroll: true, replace: true });

    return (
        <CrmLayout breadcrumbs={hubBreadcrumbs('ledger', 'team')}>
            <Head title="Сводка отдела — CRM" />
            <PageHeader
                title="Сводка отдела"
                description="Где отдел сейчас и что случится к концу месяца. Те же снимки, что видят работники."
                actions={(
                    <HStack gap={2} flexWrap="wrap">
                        <select aria-label="Месяц" style={selectStyle} value={month.slice(0, 7)} onChange={(e) => changeMonth(e.target.value)}>
                            {months.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
                        </select>
                        <Button size="sm" variant="outline" asChild>
                            <a href={`/crm/motivation/team/export?month=${month.slice(0, 7)}`}><LuDownload /> XLSX</a>
                        </Button>
                    </HStack>
                )}
            />
            <MotivationTabs hub="ledger" current="team" />

            <VStack align="stretch" gap={4}>
                <SimpleGrid columns={{ base: 2, md: 5 }} gap={3}>
                    <Stat label="План месяца" value={department.plan > 0 ? fmtRub0(department.plan) : '—'} />
                    <Stat label="Отгружено базе" value={fmtRub0(department.shipped)} hint="Отгрузки закреплённой базы за вычетом возвратов, по всем работникам. Отгрузки новым партнёрам — отдельно, в П2." />
                    <Stat label="Выполнение" value={department.percent === null ? '—' : fmtPercent(department.percent, 0)} tone={department.percent === null ? undefined : department.percent >= 1 ? 'green' : undefined} />
                    <Stat label="Прогноз к концу месяца" value={department.forecast === null ? '—' : fmtRub0(department.forecast)} hint={isCurrent ? 'Доход всех работников, если так и пойдёт: тот же темп отгрузок и долгов до конца месяца.' : 'Прогноз строится только для текущего месяца.'} />
                    <Stat label="Фонд оплаты за месяц" value={fmtRub0(department.payroll)} hint={department.note} />
                    {department.parallel && (
                        <Stat
                            label={department.parallel.phase === 'before' ? 'Стоимость перехода' : 'Против прежней системы'}
                            value={fmtSigned(department.parallel.difference)}
                            tone={department.parallel.difference > 0 ? 'orange' : department.parallel.difference < 0 ? 'green' : undefined}
                            hint={`Сумма разниц по работникам: фонд по схеме «${department.parallel.scheme_label}» ${fmtRub0(department.parallel.total)} против оплачиваемого. Справочно, в ведомость не входит (п. 12.2).`}
                        />
                    )}
                </SimpleGrid>

                {rows.some((r) => !r.on_scheme_v2) && (
                    <Alert status="info" title="Часть работников считается по прежней схеме">
                        Показатели П1–К1 у них не рассчитываются. Схема 2.2 вводится приказом на экране «Параметры мотивации».
                    </Alert>
                )}

                <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflowX="auto">
                    <Table.Root size="sm">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeader>Работник</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">План</Table.ColumnHeader>
                                <Table.ColumnHeader>Отгружено</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">П1</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">П2</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">П3</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">К1</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">Переменная</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">Итого</Table.ColumnHeader>
                                {department.parallel && <Table.ColumnHeader textAlign="right"><HStack justify="flex-end" gap={1}>{department.parallel.phase === 'before' ? 'По новой' : 'По прежней'}<MetricHint text={`Справочный расчёт по схеме «${department.parallel.scheme_label}» на тех же данных (п. 12.2). В ведомость не попадает.`} /></HStack></Table.ColumnHeader>}
                                {department.parallel && <Table.ColumnHeader textAlign="right">Разница</Table.ColumnHeader>}
                                <Table.ColumnHeader textAlign="right">Просрочка</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">Активных</Table.ColumnHeader>
                                <Table.ColumnHeader>Статус</Table.ColumnHeader>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {rows.map((r) => (
                                <Table.Row key={r.manager.id}>
                                    <Table.Cell>
                                        <Link href={`/crm/motivation?manager=${r.manager.id}&month=${month.slice(0, 7)}`}>
                                            <Text fontSize="sm" fontWeight="600" _hover={{ textDecoration: 'underline' }}>{r.manager.name}</Text>
                                        </Link>
                                        {r.warnings.length > 0 && <Text fontSize="xs" color="orange.fg">{r.warnings[0]}</Text>}
                                    </Table.Cell>
                                    <Table.Cell textAlign="right"><Text fontSize="sm" fontVariantNumeric="tabular-nums">{r.plan ? fmtRub0(r.plan) : '—'}</Text></Table.Cell>
                                    <Table.Cell minW="150px">
                                        <Text fontSize="sm" fontVariantNumeric="tabular-nums">{fmtRub0(r.shipped)}{r.percent !== null ? ` · ${fmtPercent(r.percent, 0)}` : ''}</Text>
                                        {r.percent !== null && (
                                            <Box h="5px" bg="bg.muted" borderRadius="full" overflow="hidden" mt={1}>
                                                <Box h="100%" w={`${Math.min(100, r.percent * 100)}%`} bg={r.percent >= 1 ? 'green.solid' : 'blue.solid'} />
                                            </Box>
                                        )}
                                    </Table.Cell>
                                    <Table.Cell textAlign="right"><Money v={r.p1} /></Table.Cell>
                                    <Table.Cell textAlign="right"><Money v={r.p2} /></Table.Cell>
                                    <Table.Cell textAlign="right"><Money v={r.p3} /></Table.Cell>
                                    <Table.Cell textAlign="right"><Text fontSize="sm" color={r.k1 > 0 ? 'red.fg' : 'fg.subtle'} fontVariantNumeric="tabular-nums">{r.k1 > 0 ? fmtSigned(-r.k1) : '—'}</Text></Table.Cell>
                                    <Table.Cell textAlign="right"><Money v={r.variable} strong /></Table.Cell>
                                    <Table.Cell textAlign="right"><Text fontSize="sm" fontWeight="800" fontVariantNumeric="tabular-nums">{fmtRub0(r.total)}</Text></Table.Cell>
                                    {department.parallel && <Table.Cell textAlign="right"><Text fontSize="sm" fontVariantNumeric="tabular-nums">{r.parallel ? fmtRub0(r.parallel.total) : '—'}</Text></Table.Cell>}
                                    {department.parallel && <Table.Cell textAlign="right"><Text fontSize="sm" fontWeight="700" fontVariantNumeric="tabular-nums" color={!r.parallel ? 'fg.subtle' : r.parallel.difference > 0 ? 'green.fg' : r.parallel.difference < 0 ? 'red.fg' : 'fg.subtle'}>{r.parallel ? fmtSigned(r.parallel.difference) : '—'}</Text></Table.Cell>}
                                    <Table.Cell textAlign="right">
                                        <Text fontSize="sm" fontVariantNumeric="tabular-nums" color={r.overdue > 0 ? 'orange.fg' : 'fg.subtle'}>{r.overdue > 0 ? fmtRub0(r.overdue) : '—'}</Text>
                                        {r.overdue_share !== null && r.overdue > 0 && <Text fontSize="xs" color="fg.subtle">{fmtPercent(r.overdue_share, 0)} от вала</Text>}
                                    </Table.Cell>
                                    <Table.Cell textAlign="right"><Text fontSize="sm">{r.active_partners} из {r.partners_total}</Text></Table.Cell>
                                    <Table.Cell>
                                        <Badge colorPalette={STATUS_PALETTE[r.calculation.status] ?? 'blue'} variant="subtle" size="sm">
                                            {r.calculation.frozen && <LuLock size={10} />} {r.calculation.status_label}{r.calculation.version > 1 ? ` · v${r.calculation.version}` : ''}
                                        </Badge>
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </Table.Root>
                </Box>

                <Text fontSize="xs" color="fg.subtle">{monthLabel} · Квартальная премия отдела в фонд месяца не входит и на месячный доход не влияет.</Text>
            </VStack>
        </CrmLayout>
    );
}

function Money({ v, strong = false }) {
    return <Text fontSize="sm" fontWeight={strong ? '700' : undefined} color={v > 0 ? undefined : 'fg.subtle'} fontVariantNumeric="tabular-nums">{v > 0 ? fmtRub0(v) : '—'}</Text>;
}

function Stat({ label, value, tone, hint }) {
    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
            <HStack gap={1} fontSize="xs" color="fg.muted">
                <Text>{label}</Text>
                {hint && <MetricHint text={hint} />}
            </HStack>
            <Text fontSize="xl" fontWeight="800" fontVariantNumeric="tabular-nums" color={tone ? `${tone}.fg` : undefined}>{value}</Text>
        </Box>
    );
}
