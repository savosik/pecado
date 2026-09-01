import { Head, router } from '@inertiajs/react';
import { Box, Flex, HStack, Table, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { SegmentedControl } from '@/components/ui/segmented-control';
import MetricHint from '@/Crm/Components/MetricHint';
import ShareBar from '@/Crm/Components/ShareBar';
import FinanceFilterBar from './components/FinanceFilterBar';
import FinanceRowsTable from './components/FinanceRowsTable';
import PartnerFinanceCell from './components/PartnerFinanceCell';
import PlanCalendar from './components/PlanCalendar';
import DayDrawer from './components/DayDrawer';
import { formatCompact, formatRub } from './components/format';

/**
 * План поступлений — график платежей из 1С и фактические оплаты.
 *
 * Раздел ничего не предсказывает. Прежняя версия взвешивала тот же график на
 * «платёжную дисциплину» партнёра и достраивала регрессией то, чего в графике
 * нет; финансисту это мешало, потому что бюджет верстают по обязательствам, а
 * не по матожиданию. Теперь на экране только то, что есть в учётной системе:
 * отгрузили — срок назначен — вот и план.
 *
 * Заказы в план не входят: клиент может отменить заказ, и не случится ничего.
 * Обязательство создаёт отгрузка, и вместе с реализацией приходит её
 * собственный график — по нему и ждём деньги. Просрочка вынесена отдельно и
 * в ожидания периода не входит: её срок прошёл, и приписать её будущему дню
 * значило бы выдумать срок.
 */
export default function FinancePlan({
    view = 'period',
    today,
    summary = {},
    overdue = {},
    partners = [],
    snapshots = {},
    rows = null,
    showLines = false,
    calendar = null,
    day = null,
    dayPlan = [],
    dayFacts = [],
    filters = {},
    managers = [],
    organizations = [],
    seesAll = false,
}) {
    const go = (patch, options = {}) => {
        const query = new URLSearchParams(window.location.search);

        Object.entries(patch).forEach(([key, value]) => {
            if (value === undefined || value === null || value === '') query.delete(key);
            else query.set(key, value);
        });

        query.delete('page');
        router.get(`/crm/finance/plan?${query.toString()}`, {}, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            ...options,
        });
    };

    const isPast = filters.date_to && filters.date_to < today;

    return (
        <CrmLayout breadcrumbs={[{ label: 'Финансы' }, { label: 'План поступлений' }]}>
            <Head title="План поступлений — CRM" />

            <PageHeader
                title="План поступлений"
                description="График платежей из 1С и фактические оплаты. Без прогнозов — только то, что в учётной системе"
            />

            <FinanceFilterBar
                routeName="crm.finance.plan"
                exportRoute="crm.finance.plan.export"
                filters={filters}
                managers={managers}
                organizations={organizations}
                seesAll={seesAll}
                hidePeriod={view === 'calendar'}
                passthrough={['view', 'month', 'day', 'group']}
                extraControls={(
                    <SegmentedControl
                        size="sm"
                        value={view}
                        items={[
                            { value: 'period', label: 'Период' },
                            { value: 'calendar', label: 'Календарь' },
                        ]}
                        onValueChange={(event) => go({ view: event.value, day: null })}
                    />
                )}
            />

            {summary.beyond_horizon && (
                <Box borderWidth="1px" borderColor="orange.muted" borderRadius="lg" px={4} py={3} mb={3} bg="orange.subtle">
                    <HStack gap={2} wrap="wrap">
                        <Text fontSize="sm" fontWeight="600">
                            График 1С заканчивается {summary.horizon_label}
                        </Text>
                        <Text fontSize="xs" color="fg.muted">
                            Дальше плановых строк нет — это отсутствие данных, а не ноль поступлений.
                            1С присылает график примерно на месяц вперёд.
                        </Text>
                    </HStack>
                </Box>
            )}

            {overdue.total > 0 && (
                <OverdueBlock overdue={overdue} asOfLabel={filters.date_from} />
            )}

            <Box borderWidth="1px" borderRadius="lg" p={4} mb={3} bg="bg.panel">
                <Flex gap={{ base: 4, md: 8 }} wrap="wrap" align="flex-end">
                    <VStack align="start" gap={0}>
                        <HStack gap={1}>
                            <Text fontSize="xs" color="fg.muted">
                                {isPast ? 'Ожидалось в периоде' : 'Ожидается по графику'}
                            </Text>
                            <MetricHint text="Сумма непогашенных строк графика оплаты из 1С по отгруженным документам, чей срок попадает в выбранный период. Это обязательства, а не оценка: сколько учётная система назначила к оплате, столько и показано. Заказы в план не входят — обязательство создаёт отгрузка, и вместе с реализацией приходит её собственный график. Просроченное показано отдельным блоком выше и в эту сумму не включено." />
                        </HStack>
                        <Text fontSize="3xl" fontWeight="700" lineHeight="1.1">
                            {formatRub(summary.total)}
                        </Text>
                        {isPast && (
                            <Text fontSize="10px" color="fg.muted">
                                период в прошлом — это осталось незакрытым
                            </Text>
                        )}
                    </VStack>

                    <VStack align="start" gap={0}>
                        <Text fontSize="10px" color="fg.muted" textTransform="uppercase">Документов</Text>
                        <Text fontSize="lg" fontWeight="600">{summary.documents ?? 0}</Text>
                        <Text fontSize="10px" color="fg.muted">
                            {summary.lines ?? 0} строк графика
                        </Text>
                    </VStack>
                </Flex>
            </Box>

            {view === 'calendar' && calendar && (
                <PlanCalendar
                    calendar={calendar}
                    today={today}
                    horizon={summary.horizon}
                    selectedDay={day}
                    onSelectDay={(date) => go({ day: date === day ? null : date })}
                    onChangeMonth={(month) => go({ month, day: null })}
                />
            )}

            {view === 'period' && (
                <>
                    <Flex justify="space-between" align="baseline" mb={2} gap={3} wrap="wrap">
                        <HStack gap={2}>
                            <Text fontWeight="600">От кого ждём</Text>
                            <MetricHint text="Кто должен заплатить в выбранном периоде по графику 1С. Рядом с каждым партнёром — его текущий долг, доля просрочки в нём и последний платёж: сумма к сроку читается иначе, когда видно, платит ли он вообще." />
                        </HStack>

                        <Text
                            as="button"
                            type="button"
                            fontSize="xs"
                            color="blue.fg"
                            textDecoration="underline"
                            onClick={() => go({ group: showLines ? null : 'none' })}
                        >
                            {showLines ? 'Скрыть строки графика' : 'Показать строки графика'}
                        </Text>
                    </Flex>

                    <PartnersTable partners={partners} snapshots={snapshots} />

                    {showLines && rows && (
                        <Box mt={6}>
                            <Text fontWeight="600" mb={2}>Строки графика</Text>
                            <FinanceRowsTable rows={rows} emptyMessage="В выбранном периоде плановых строк нет" />
                        </Box>
                    )}
                </>
            )}

            <DayDrawer
                date={day}
                plan={dayPlan}
                facts={dayFacts}
                snapshots={snapshots}
                totals={day ? calendar?.days?.[day] ?? null : null}
                isPast={Boolean(day) && day < today}
                onClose={() => go({ day: null })}
            />
        </CrmLayout>
    );
}

/**
 * Просрочка на начало периода.
 *
 * Отдельным блоком и над периодом, потому что в дни её ставить нельзя: срок
 * прошёл, и любая дата, которую мы ей припишем, будет выдуманной.
 */
function OverdueBlock({ overdue, asOfLabel }) {
    const total = overdue.total ?? 0;

    return (
        <Box borderWidth="1px" borderColor="red.muted" borderRadius="lg" px={4} py={3} mb={3} bg="red.subtle">
            <HStack gap={2} mb={2} wrap="wrap">
                <Text fontSize="sm" fontWeight="600">
                    Просрочено на {asOfLabel ? asOfLabel.split('-').reverse().join('.') : 'начало периода'}
                </Text>
                <Text fontSize="sm" fontWeight="700" color="red.fg">{formatRub(total)}</Text>
                <Text fontSize="xs" color="fg.muted">
                    {overdue.lines} строк · самая давняя ждёт {overdue.oldest_days} дн.
                </Text>
                <MetricHint text="Строки графика, чей срок прошёл, а деньги не пришли. В сумму «ожидается в периоде» они не входят и по дням календаря не раскладываются: их срок уже был. Счёт строгий — всё просроченное хоть на день, без льготы и без отсечки по сумме, поэтому число сходится с разделом «Просрочка», но может быть больше, чем показывает «Дебиторка»." />
            </HStack>

            <Flex height="10px" borderRadius="full" overflow="hidden" bg="bg.muted" mb={2}>
                {(overdue.buckets ?? []).map((bucket, index) => {
                    const share = total > 0 ? (bucket.amount / total) * 100 : 0;

                    return share > 0 ? (
                        <Box
                            key={bucket.key}
                            width={`${share}%`}
                            bg={BUCKET_COLORS[index] ?? 'red.solid'}
                            title={`${bucket.label}: ${formatRub(bucket.amount)}`}
                        />
                    ) : null;
                })}
            </Flex>

            <HStack gap={4} wrap="wrap">
                {(overdue.buckets ?? []).filter((bucket) => bucket.amount > 0).map((bucket, index) => (
                    <HStack key={bucket.key} gap={1}>
                        <Box width="8px" height="8px" borderRadius="full" bg={BUCKET_COLORS[index] ?? 'red.solid'} />
                        <Text fontSize="10px" color="fg.muted">
                            {bucket.label} — {formatCompact(bucket.amount)} ({bucket.lines})
                        </Text>
                    </HStack>
                ))}
            </HStack>
        </Box>
    );
}

/** Кто и сколько должен заплатить в периоде. */
function PartnersTable({ partners, snapshots }) {
    return (
        <Box borderWidth="1px" borderColor="border.muted" borderRadius="md" overflow="hidden">
            <Box overflowX="auto">
                <Table.Root size="sm" variant="line">
                    <Table.Header>
                        <Table.Row>
                            <Table.ColumnHeader>Партнёр</Table.ColumnHeader>
                            <Table.ColumnHeader>Состояние партнёра</Table.ColumnHeader>
                            <Table.ColumnHeader textAlign="end">Ждём в периоде</Table.ColumnHeader>
                            <Table.ColumnHeader textAlign="end">Документов</Table.ColumnHeader>
                        </Table.Row>
                    </Table.Header>

                    <Table.Body>
                        {partners.length === 0 && (
                            <Table.Row>
                                <Table.Cell colSpan={4}>
                                    <Text py={8} textAlign="center" color="fg.muted">
                                        В выбранном периоде поступлений по графику не ожидается
                                    </Text>
                                </Table.Cell>
                            </Table.Row>
                        )}

                        {partners.map((partner) => (
                            <Table.Row key={partner.user_id} _hover={{ bg: 'bg.muted' }}>
                                <Table.Cell>
                                    <VStack align="start" gap={0}>
                                        <Box
                                            as="a"
                                            href={partner.url}
                                            fontSize="sm"
                                            fontWeight="600"
                                            _hover={{ color: 'blue.fg', textDecoration: 'underline' }}
                                        >
                                            {partner.title}
                                        </Box>
                                        {partner.manager_name && (
                                            <Text fontSize="10px" color="fg.muted">{partner.manager_name}</Text>
                                        )}
                                    </VStack>
                                </Table.Cell>

                                <Table.Cell>
                                    <PartnerFinanceCell finance={snapshots[partner.user_id]} />
                                </Table.Cell>

                                <Table.Cell textAlign="end">
                                    <Text fontSize="sm" fontWeight="600" whiteSpace="nowrap">
                                        {formatRub(partner.total)}
                                    </Text>
                                </Table.Cell>

                                <Table.Cell textAlign="end">
                                    <Text fontSize="xs" color="fg.muted">{partner.documents}</Text>
                                </Table.Cell>
                            </Table.Row>
                        ))}
                    </Table.Body>
                </Table.Root>
            </Box>
        </Box>
    );
}

/** Цвета возрастных корзин: от свежей задержки к застарелому долгу. */
const BUCKET_COLORS = ['orange.solid', 'orange.emphasized', 'red.muted', 'red.solid', 'red.emphasized'];
