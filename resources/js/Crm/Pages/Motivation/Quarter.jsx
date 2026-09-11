import { Head, Link, router } from '@inertiajs/react';
import { Badge, Box, HStack, SimpleGrid, Table, Text, VStack } from '@chakra-ui/react';
import { LuLock } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Alert } from '@/components/ui/alert';
import MetricHint from '@/Crm/Components/MetricHint';
import { fmtDay, fmtRub0, plural } from '../Salary/components/format';
import MotivationTabs from './components/MotivationTabs';
import { hubBreadcrumbs } from './components/hubs';
import FoldSection from './components/FoldSection';

const selectStyle = {
    padding: '0.45rem 0.6rem',
    borderRadius: '0.5rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '180px',
    background: 'var(--chakra-colors-bg-panel)',
    color: 'var(--chakra-colors-fg)',
};

/**
 * «Премия отдела за квартал»: сколько отделу осталось до премии и что зависит от меня.
 *
 * Зачёт поклиентный: средняя покупка на партнёра не выводится нигде — она
 * создавала бы впечатление, что «в среднем дотянули».
 */
export default function MotivationQuarter({ month, data }) {
    const navigate = (changes) => router.get('/crm/motivation/quarter', { month, ...changes }, { preserveState: true, preserveScroll: true, replace: true });

    const quarterOptions = [];
    const now = new Date();
    for (let i = 0; i < 6; i++) {
        const d = new Date(now.getFullYear(), now.getMonth() - i * 3, 1);
        const q = Math.floor(d.getMonth() / 3);
        const value = `${d.getFullYear()}-${String(q * 3 + 1).padStart(2, '0')}`;
        if (!quarterOptions.find((o) => o.value === value)) {
            quarterOptions.push({ value, label: `${['I', 'II', 'III', 'IV'][q]} квартал ${d.getFullYear()}` });
        }
    }

    return (
        <CrmLayout breadcrumbs={hubBreadcrumbs('payslip', 'quarter')}>
            <Head title="Премия отдела за квартал — CRM" />
            <PageHeader
                title="Премия отдела за квартал"
                description="Сколько отделу осталось до премии и что зависит от вас."
                actions={(
                    <select aria-label="Квартал" style={selectStyle} value={data ? data.quarter.slice(0, 7) : month} onChange={(e) => navigate({ month: e.target.value })}>
                        {quarterOptions.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                    </select>
                )}
            />
            <MotivationTabs hub="payslip" current="quarter" />

            <VStack align="stretch" gap={4} maxW="1100px">
                {data && (
                    <>
                        <Alert status="info" title="Премия начисляется на отдел и на ваш месячный доход не влияет">
                            {data.note}
                        </Alert>

                        <SimpleGrid columns={{ base: 1, sm: 3 }} gap={3}>
                            <Stat label="Засчитано партнёров" value={`${data.qualified_count} из ${data.next_step ? data.next_step.count : data.steps[data.steps.length - 1]?.count ?? 0}`} hint={`Партнёр засчитан, если он новый и его отгрузки за квартал за вычетом возвратов не меньше ${fmtRub0(data.qualification_amount)}.`} />
                            <Stat label="Взятая ступень" value={data.step_reached > 0 ? `${data.step_reached} · ${fmtRub0(data.amount)}` : 'пока нет'} tone={data.step_reached > 0 ? 'green' : undefined} />
                            <Stat label={data.days_left > 0 ? 'До конца квартала' : 'Квартал'} value={data.days_left > 0 ? `${data.days_left} ${plural(data.days_left, 'день', 'дня', 'дней')}` : 'завершён'} />
                        </SimpleGrid>

                        {data.frozen && (
                            <HStack fontSize="sm" color="fg.muted" gap={2}><LuLock size={14} /><Text>Итог квартала утверждён и не меняется.</Text></HStack>
                        )}

                        <SimpleGrid columns={{ base: 1, md: 3 }} gap={3}>
                            {data.steps.map((step, i) => (
                                <Box key={step.count} bg="bg.panel" borderWidth="2px" borderColor={step.reached ? 'green.solid' : 'border'} borderRadius="xl" p={4} opacity={step.reached || i === (data.step_reached) ? 1 : 0.7}>
                                    <HStack justify="space-between" mb={1}>
                                        <Text fontSize="xs" color="fg.muted">Ступень {i + 1}</Text>
                                        {step.reached && <Badge colorPalette="green" variant="subtle" size="xs">взята</Badge>}
                                    </HStack>
                                    <Text fontSize="sm">
                                        <Text as="span" fontWeight="700">{step.count} новых {plural(step.count, 'клиент', 'клиента', 'клиентов')}</Text>, и каждый из них купил за квартал не менее {fmtRub0(data.qualification_amount)}
                                    </Text>
                                    <Text fontSize="xl" fontWeight="800" mt={1} color={step.reached ? 'green.fg' : undefined}>→ {fmtRub0(step.amount)} на отдел</Text>
                                </Box>
                            ))}
                        </SimpleGrid>

                        {data.next_step && (
                            <Text fontSize="sm" color="fg.muted">
                                До следующей ступени не хватает {data.next_step.partners_needed} {plural(data.next_step.partners_needed, 'партнёра', 'партнёров', 'партнёров')} — она даёт {fmtRub0(data.next_step.amount)}.
                            </Text>
                        )}

                        <FoldSection title={'Кто засчитан'} summary={`${data.candidates.length} ${plural(data.candidates.length, 'кандидат', 'кандидата', 'кандидатов')}`}>
                            <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflowX="auto">
                                {data.candidates.length === 0 ? (
                                    <Text px={4} pb={4} fontSize="sm" color="fg.muted">Новых партнёров с отгрузками в этом квартале нет.</Text>
                                ) : (
                                    <Table.Root size="sm">
                                        <Table.Header>
                                            <Table.Row>
                                                <Table.ColumnHeader>Партнёр</Table.ColumnHeader>
                                                <Table.ColumnHeader>Чей</Table.ColumnHeader>
                                                <Table.ColumnHeader>Первая покупка</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="right">Купил за квартал</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="right">Зачёт</Table.ColumnHeader>
                                            </Table.Row>
                                        </Table.Header>
                                        <Table.Body>
                                            {data.candidates.map((c) => (
                                                <Table.Row key={c.partner_id}>
                                                    <Table.Cell><Link href={`/crm/partners/${c.partner_id}`}><Text fontSize="sm" fontWeight="600" _hover={{ textDecoration: 'underline' }}>{c.name}</Text></Link></Table.Cell>
                                                    <Table.Cell><Text fontSize="sm" color="fg.muted">{c.manager ?? '—'}</Text></Table.Cell>
                                                    <Table.Cell><Text fontSize="sm">{c.first_purchase_on ? fmtDay(c.first_purchase_on) : '—'}</Text></Table.Cell>
                                                    <Table.Cell textAlign="right"><Text fontSize="sm" fontVariantNumeric="tabular-nums">{fmtRub0(c.quarter_amount)}</Text></Table.Cell>
                                                    <Table.Cell textAlign="right">
                                                        {c.qualified
                                                            ? <Badge size="xs" colorPalette="green" variant="subtle">засчитан</Badge>
                                                            : <Text fontSize="sm" color="fg.muted" fontVariantNumeric="tabular-nums">ещё {fmtRub0(c.shortfall)}</Text>}
                                                    </Table.Cell>
                                                </Table.Row>
                                            ))}
                                        </Table.Body>
                                    </Table.Root>
                                )}
                            </Box>
                        </FoldSection>
                    </>
                )}
            </VStack>
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
