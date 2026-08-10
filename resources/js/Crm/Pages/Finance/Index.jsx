import { Head, Link } from '@inertiajs/react';
import { Badge, Box, Flex, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Legend,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { LuTriangleAlert } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import FinanceTabs from './components/FinanceTabs';
import FinanceFilterBar from './components/FinanceFilterBar';
import FinanceRowsTable from './components/FinanceRowsTable';
import { formatCompact, formatRub } from './components/format';

/**
 * Пульт платежей: сколько денег ждём, когда и кто задерживает.
 *
 * Все суммы уже сведены в рубли на сервере (PaymentForecastService) — здесь
 * только показ. Просрочка не зависит от периода фильтров: она нужна менеджеру
 * всегда, в каком бы окне он ни смотрел план.
 */
export default function FinanceDashboard({
    summary = {},
    buckets = [],
    aging = {},
    topDebtors = [],
    overdueRows = [],
    upcomingRows = [],
    noScheduleCount = 0,
    filters = {},
    managers = [],
    organizations = [],
    seesAll = false,
}) {
    const hasOverdue = (summary.overdue_amount || 0) > 0;

    return (
        <CrmLayout breadcrumbs={[{ label: 'Финансы' }]}>
            <Head title="Платежи — CRM" />

            <PageHeader
                title="Пульт платежей"
                description="План поступлений, просрочка и балансы партнёров по данным 1С"
            />

            <FinanceTabs active="index" />

            <FinanceFilterBar
                routeName="crm.finance.index"
                filters={filters}
                managers={managers}
                organizations={organizations}
                seesAll={seesAll}
                showGranularity
            />

            <SimpleGrid columns={{ base: 1, md: 2, xl: 4 }} gap={3} mb={4}>
                <Tile
                    label="Ожидается за период"
                    value={formatRub(summary.expected_period)}
                    hint="По графику оплаты реализаций"
                />
                <Tile
                    label="Ближайшие 7 дней"
                    value={formatRub(summary.expected_7)}
                    hint={`За 14 дней: ${formatCompact(summary.expected_14)} · за 30: ${formatCompact(summary.expected_30)}`}
                />
                <Tile
                    label="Просрочено"
                    value={formatRub(summary.overdue_amount)}
                    hint={`Строк: ${summary.overdue_count || 0} · партнёров: ${summary.overdue_clients || 0}`}
                    tone={hasOverdue ? 'red' : undefined}
                />
                <Tile
                    label="Долг партнёров по 1С"
                    value={formatRub(summary.debt_total)}
                    hint={`Просрочка по 1С: ${formatCompact(summary.erp_overdue_total)} · авансы: ${formatCompact(summary.advances)}`}
                />
            </SimpleGrid>

            {noScheduleCount > 0 && (
                <Box borderWidth="1px" borderColor="orange.muted" bg="orange.subtle" borderRadius="lg" p={3} mb={4}>
                    <HStack gap={2} align="start">
                        <Box color="orange.fg" mt="2px"><LuTriangleAlert /></Box>
                        <Text fontSize="sm">
                            <b>{formatRub(summary.no_schedule_amount)}</b> долга — по {noScheduleCount} реализациям,
                            для которых 1С не прислала график оплаты. В плане по датам эти деньги не учтены:
                            срок платежа неизвестен. Полный список — на странице{' '}
                            <Box as={Link} href="/crm/finance/plan" textDecoration="underline">План поступлений</Box>.
                        </Text>
                    </HStack>
                </Box>
            )}

            <Box borderWidth="1px" borderRadius="lg" p={4} mb={4}>
                <Text fontWeight="600" mb={3}>План и факт поступлений</Text>

                <Box height="280px">
                    <ResponsiveContainer width="100%" height="100%">
                        <BarChart data={buckets} margin={{ top: 8, right: 8, left: 8, bottom: 8 }}>
                            <CartesianGrid strokeDasharray="3 3" opacity={0.3} />
                            <XAxis dataKey="label" fontSize={11} />
                            <YAxis fontSize={11} tickFormatter={(value) => formatCompact(value)} width={90} />
                            <Tooltip
                                formatter={(value, name) => [formatRub(value), name]}
                                labelFormatter={(label) => `Период: ${label}`}
                            />
                            <Legend />
                            <Bar dataKey="plan" name="План" fill="#3182ce" radius={[4, 4, 0, 0]} />
                            <Bar dataKey="fact" name="Факт" fill="#38a169" radius={[4, 4, 0, 0]} />
                        </BarChart>
                    </ResponsiveContainer>
                </Box>
            </Box>

            <SimpleGrid columns={{ base: 1, xl: 2 }} gap={4} mb={4}>
                <Box borderWidth="1px" borderRadius="lg" p={4}>
                    <Text fontWeight="600" mb={3}>Просрочка по срокам</Text>

                    <VStack align="stretch" gap={2}>
                        {(aging.buckets || []).map((bucket) => (
                            <Flex key={bucket.key} justify="space-between" align="center">
                                <Text fontSize="sm">{bucket.label}</Text>
                                <HStack gap={3}>
                                    <Text fontSize="xs" color="fg.muted">{bucket.count} стр.</Text>
                                    <Text fontSize="sm" fontWeight="600">{formatRub(bucket.amount)}</Text>
                                </HStack>
                            </Flex>
                        ))}

                        <Flex justify="space-between" align="center" pt={2} borderTopWidth="1px">
                            <Text fontSize="sm" fontWeight="600">Итого просрочено</Text>
                            <Text fontSize="sm" fontWeight="700" color={hasOverdue ? 'red.fg' : undefined}>
                                {formatRub(aging.total)}
                            </Text>
                        </Flex>
                    </VStack>
                </Box>

                <Box borderWidth="1px" borderRadius="lg" p={4}>
                    <Text fontWeight="600" mb={3}>Кому звонить</Text>

                    {topDebtors.length === 0 ? (
                        <Text fontSize="sm" color="fg.muted">Просроченных платежей нет.</Text>
                    ) : (
                        <VStack align="stretch" gap={2}>
                            {topDebtors.map((debtor) => (
                                <Flex key={debtor.id} justify="space-between" align="center" gap={3}>
                                    <VStack align="start" gap={0} minW={0}>
                                        <Box
                                            as="a"
                                            href={debtor.url}
                                            fontSize="sm"
                                            _hover={{ color: 'blue.fg', textDecoration: 'underline' }}
                                        >
                                            {debtor.name}
                                        </Box>
                                        <Text fontSize="10px" color="fg.muted">
                                            {debtor.manager_name || 'без менеджера'} · {debtor.count} док.
                                        </Text>
                                    </VStack>
                                    <HStack gap={2} flexShrink={0}>
                                        <Badge size="xs" colorPalette="red">{debtor.max_days} дн.</Badge>
                                        <Text fontSize="sm" fontWeight="600">{formatRub(debtor.amount)}</Text>
                                    </HStack>
                                </Flex>
                            ))}
                        </VStack>
                    )}
                </Box>
            </SimpleGrid>

            <Box borderWidth="1px" borderRadius="lg" p={4} mb={4}>
                <Flex justify="space-between" align="center" mb={3}>
                    <Text fontWeight="600">Просроченные платежи</Text>
                    <Button asChild size="xs" variant="outline">
                        <Link href="/crm/finance/overdue">Все просроченные</Link>
                    </Button>
                </Flex>

                <FinanceRowsTable rows={overdueRows} emptyMessage="Просроченных платежей нет" />
            </Box>

            <Box borderWidth="1px" borderRadius="lg" p={4}>
                <Flex justify="space-between" align="center" mb={3}>
                    <Text fontWeight="600">Ближайшие поступления</Text>
                    <Button asChild size="xs" variant="outline">
                        <Link href="/crm/finance/plan">Весь план</Link>
                    </Button>
                </Flex>

                <FinanceRowsTable rows={upcomingRows} emptyMessage="В выбранном периоде поступлений не ожидается" />
            </Box>
        </CrmLayout>
    );
}

const Tile = ({ label, value, hint, tone }) => (
    <Box borderWidth="1px" borderRadius="lg" p={4} bg={tone === 'red' ? 'red.subtle' : undefined}>
        <Text fontSize="xs" color="fg.muted">{label}</Text>
        <Text fontSize="xl" fontWeight="700" mt={1} color={tone === 'red' ? 'red.fg' : undefined}>
            {value}
        </Text>
        {hint && <Text fontSize="10px" color="fg.muted" mt={1}>{hint}</Text>}
    </Box>
);
