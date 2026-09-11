import { Head, Link, router } from '@inertiajs/react';
import { Badge, Box, HStack, SimpleGrid, Table, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import MetricHint from '@/Crm/Components/MetricHint';
import { fmtPercent, fmtRub0 } from '../Salary/components/format';

const inputStyle = {
    padding: '0.45rem 0.6rem',
    borderRadius: '0.5rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '150px',
    background: 'var(--chakra-colors-bg-panel)',
    color: 'var(--chakra-colors-fg)',
};

/**
 * «Здоровье базы»: семь показателей по работнику и отделу, каждый раскрывается
 * в список партнёров. Цифры — те же строки, что на экранах работника.
 */
export default function MotivationHealth(props) {
    const { month, month_label: monthLabel, rows, department, quarter, pool, limits } = props;

    const navigate = (m) => router.get('/crm/motivation/health', { month: m }, { preserveState: true, preserveScroll: true, replace: true });

    const Cell = ({ href, alert, children }) => (
        <Table.Cell textAlign="right">
            <Link href={href}><Text as="span" fontSize="sm" fontVariantNumeric="tabular-nums" color={alert ? 'red.fg' : undefined} fontWeight={alert ? '700' : '400'} _hover={{ textDecoration: 'underline' }}>{children}</Text></Link>
        </Table.Cell>
    );

    return (
        <CrmLayout breadcrumbs={[{ label: 'Мотивация' }, { label: 'Здоровье базы' }]}>
            <Head title="Здоровье базы — CRM" />
            <PageHeader
                title="Здоровье базы"
                description={`Что происходит с клиентской базой отдела · ${monthLabel}. Показатели считаются теми же сервисами, что экраны работника.`}
                actions={<input type="month" aria-label="Месяц" style={inputStyle} value={month.slice(0, 7)} onChange={(e) => navigate(e.target.value)} />}
            />

            <VStack align="stretch" gap={4}>
                <SimpleGrid columns={{ base: 2, md: 4 }} gap={3}>
                    <Stat label="Вал отдела за месяц" value={fmtRub0(department.revenue)} />
                    <Stat label="Партнёров с отгрузками" value={`${department.active} из ${department.partners_total}`} hint={`месяцем раньше ${department.active_previous}`} alert={department.active_previous > 0 && department.active < department.active_previous} />
                    <Stat label="Просрочка к валу" value={department.overdue_share === null ? '—' : fmtPercent(department.overdue_share, 0)} hint={fmtRub0(department.overdue)} alert={department.overdue_share !== null && department.overdue_share > limits.overdue} />
                    <Stat label="Новых за квартал" value={`${quarter.candidates} · засчитано ${quarter.qualified}`} hint={quarter.next_step ? `до ступени ещё ${quarter.next_step.partners_needed}` : 'верхняя ступень взята'} href={quarter.href} />
                </SimpleGrid>

                <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflowX="auto">
                    <Table.Root size="sm">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeader>Работник</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right"><HStack justify="flex-end" gap={1}>Крупнейший партнёр<MetricHint text={`Доля крупнейшего партнёра в вале работника за месяц. Выше ${Math.round(limits.concentration * 100)} % — риск концентрации.`} /></HStack></Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right"><HStack justify="flex-end" gap={1}>С отгрузками<MetricHint text="Партнёров с отгрузками в месяце из закреплённых; сигнал — падение месяц к месяцу." /></HStack></Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right"><HStack justify="flex-end" gap={1}>Ни разу не покупали<MetricHint text="Закреплённые партнёры без единой отгрузки за всю историю — доля от базы." /></HStack></Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right"><HStack justify="flex-end" gap={1}>Спят дольше 3 мес.<MetricHint text={`Покупали раньше, но молчат дольше ${limits.sleep_days} дней.`} /></HStack></Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right"><HStack justify="flex-end" gap={1}>Просрочка к валу<MetricHint text={`Просроченный долг закреплённых партнёров к отгрузкам месяца. Выше ${Math.round(limits.overdue * 100)} % — сигнал.`} /></HStack></Table.ColumnHeader>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {rows.map((r) => (
                                <Table.Row key={r.manager.id}>
                                    <Table.Cell>
                                        <Text fontSize="sm" fontWeight="600">{r.manager.name}</Text>
                                        <Text fontSize="xs" color="fg.subtle">{fmtRub0(r.revenue)} · {r.partners_total} партнёров</Text>
                                    </Table.Cell>
                                    <Cell href={r.links.active} alert={r.concentration_alert}>
                                        {r.concentration === null ? '—' : fmtPercent(r.concentration, 0)}
                                        {r.top_partner.name && <Text as="span" fontSize="xs" color="fg.subtle" display="block">{r.top_partner.name}</Text>}
                                    </Cell>
                                    <Cell href={r.links.active} alert={r.active_alert}>
                                        {r.active} из {r.partners_total}
                                        <Text as="span" fontSize="xs" color="fg.subtle" display="block">было {r.active_previous}</Text>
                                    </Cell>
                                    <Cell href={r.links.never}>
                                        {r.never_bought}
                                        <Text as="span" fontSize="xs" color="fg.subtle" display="block">{r.never_bought_share === null ? '' : fmtPercent(r.never_bought_share, 0)}</Text>
                                    </Cell>
                                    <Cell href={r.links.sleeping}>{r.sleeping}</Cell>
                                    <Cell href={r.links.debts} alert={r.overdue_alert}>
                                        {r.overdue_share === null ? '—' : fmtPercent(r.overdue_share, 0)}
                                        <Text as="span" fontSize="xs" color="fg.subtle" display="block">{fmtRub0(r.overdue)}</Text>
                                    </Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </Table.Root>
                </Box>

                <SimpleGrid columns={{ base: 1, md: 2 }} gap={3}>
                    <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
                        <HStack gap={1}><Text fontWeight="700">Новые партнёры за {quarter.label}</Text><MetricHint text="Кандидаты — партнёры в периоде новизны с отгрузками в квартале; засчитаны те, кто набрал порог квалификации." /></HStack>
                        <Text fontSize="2xl" fontWeight="800" mt={1}>{quarter.candidates} <Text as="span" fontSize="md" color="fg.muted">кандидатов · засчитано</Text> {quarter.qualified}</Text>
                        <Link href={quarter.href}><Text fontSize="sm" color="blue.fg" _hover={{ textDecoration: 'underline' }}>Открыть квартальную премию</Text></Link>
                    </Box>
                    <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
                        <HStack gap={1}><Text fontWeight="700">Ничейные партнёры</Text><MetricHint text="Партнёры Пула на конец месяца; с историей — те, кто когда-либо отгружался." /></HStack>
                        <Text fontSize="2xl" fontWeight="800" mt={1}>{pool.total} <Text as="span" fontSize="md" color="fg.muted">· с историей</Text> {pool.with_history}</Text>
                        <Link href={pool.href}><Text fontSize="sm" color="blue.fg" _hover={{ textDecoration: 'underline' }}>Открыть пул и раздачу</Text></Link>
                    </Box>
                </SimpleGrid>

                {department.concentration !== null && department.concentration > limits.concentration && (
                    <Badge colorPalette="red" variant="subtle" alignSelf="flex-start">Крупнейший партнёр даёт {fmtPercent(department.concentration, 0)} вала отдела — риск концентрации</Badge>
                )}
            </VStack>
        </CrmLayout>
    );
}

function Stat({ label, value, hint, alert, href }) {
    const body = (
        <Box bg="bg.panel" borderWidth="1px" borderColor={alert ? 'red.solid' : 'border'} borderRadius="xl" p={4}>
            <Text fontSize="xs" color="fg.muted">{label}</Text>
            <Text fontSize="2xl" fontWeight="800" fontVariantNumeric="tabular-nums" color={alert ? 'red.fg' : undefined}>{value}</Text>
            {hint && <Text fontSize="xs" color="fg.subtle">{hint}</Text>}
        </Box>
    );
    return href ? <Link href={href}>{body}</Link> : body;
}
