import { Head, router } from '@inertiajs/react';
import { Box, HStack, SimpleGrid, Table, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Alert } from '@/components/ui/alert';
import MetricHint from '@/Crm/Components/MetricHint';
import { fmtRub0 } from '../Salary/components/format';
import MotivationTabs from './components/MotivationTabs';
import { hubBreadcrumbs } from './components/hubs';
import FoldSection from './components/FoldSection';

const inputStyle = {
    padding: '0.45rem 0.6rem',
    borderRadius: '0.5rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '150px',
    background: 'var(--chakra-colors-bg-panel)',
    color: 'var(--chakra-colors-fg)',
};

const ORDER = ['current', 'pace', 'plan', 'over'];

/**
 * Прогноз фонда оплаты труда: сколько отдел будет стоить при разных сценариях.
 * Считается тем же калькулятором с гипотетическими входами.
 */
export default function MotivationForecast({ month, month_label: monthLabel, rows, department, scenarios, notes }) {
    const navigate = (m) => router.get('/crm/motivation/forecast', { month: m }, { preserveState: true, preserveScroll: true, replace: true });

    return (
        <CrmLayout breadcrumbs={hubBreadcrumbs('ledger', 'forecast')}>
            <Head title="Прогноз фонда оплаты — CRM" />
            <PageHeader
                title="Прогноз фонда оплаты труда"
                description={`Сколько отдел будет стоить при разных сценариях · ${monthLabel}. Тот же калькулятор, что считает расчётный лист.`}
                actions={<input type="month" aria-label="Месяц" style={inputStyle} value={month.slice(0, 7)} onChange={(e) => navigate(e.target.value)} />}
            />
            <MotivationTabs hub="ledger" current="forecast" />

            <VStack align="stretch" gap={4}>
                <SimpleGrid columns={{ base: 2, md: 4 }} gap={3}>
                    {ORDER.map((key) => (
                        <Box key={key} bg="bg.panel" borderWidth="1px" borderColor={key === 'pace' ? 'blue.solid' : 'border'} borderRadius="xl" p={4}>
                            <HStack gap={1} fontSize="xs" color="fg.muted"><Text>{scenarios[key].label}</Text><MetricHint text={scenarios[key].hint} /></HStack>
                            <Text fontSize="2xl" fontWeight="800" fontVariantNumeric="tabular-nums">{department[key].available ? fmtRub0(department[key].total) : '—'}</Text>
                            {department[key].available && (
                                <Text fontSize="xs" color="fg.subtle">переменная {fmtRub0(department[key].variable)}{department[key].guarantee > 0 ? ` · гарантия ${fmtRub0(department[key].guarantee)}` : ''}</Text>
                            )}
                        </Box>
                    ))}
                </SimpleGrid>

                <FoldSection title={'По работникам'} summary={`${rows.length} ${rows.length === 1 ? 'работник' : 'работника'}`}>
                    <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflowX="auto">
                        {rows.length === 0 ? <Text p={4} fontSize="sm" color="fg.muted">Работников на новой схеме в этом месяце нет.</Text> : (
                            <Table.Root size="sm">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>Работник</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">План / отгружено</Table.ColumnHeader>
                                        {ORDER.map((key) => <Table.ColumnHeader key={key} textAlign="right">{scenarios[key].label}</Table.ColumnHeader>)}
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {rows.map((r) => (
                                        <Table.Row key={r.manager.id}>
                                            <Table.Cell>
                                                <Text fontSize="sm" fontWeight="600">{r.manager.name}</Text>
                                                <Text fontSize="xs" color="fg.subtle">{r.frozen ? 'месяц заморожен' : `${r.days.passed} из ${r.days.total} раб. дн.`}</Text>
                                            </Table.Cell>
                                            <Table.Cell textAlign="right"><Text fontSize="sm" fontVariantNumeric="tabular-nums">{r.plan === null ? '— ' : fmtRub0(r.plan)} / {fmtRub0(r.shipped)}</Text></Table.Cell>
                                            {ORDER.map((key) => {
                                                const s = r.scenarios[key];
                                                return (
                                                    <Table.Cell key={key} textAlign="right">
                                                        {s === null ? <Text fontSize="sm" color="fg.subtle">нет плана</Text> : (
                                                            <>
                                                                <Text fontSize="sm" fontWeight={key === 'pace' ? '700' : '400'} fontVariantNumeric="tabular-nums">{fmtRub0(s.total)}</Text>
                                                                <Text fontSize="xs" color="fg.subtle">перем. {fmtRub0(s.variable)}{s.guarantee > 0 ? ` · гар. ${fmtRub0(s.guarantee)}` : ''}</Text>
                                                            </>
                                                        )}
                                                    </Table.Cell>
                                                );
                                            })}
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        )}
                    </Box>
                </FoldSection>

                {notes.map((n) => <Alert key={n} status="info">{n}</Alert>)}
            </VStack>
        </CrmLayout>
    );
}
