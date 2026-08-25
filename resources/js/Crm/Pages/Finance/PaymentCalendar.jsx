import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, Flex, HStack, Table, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import MetricHint from '@/Crm/Components/MetricHint';
import PaymentCalendarGrid from '@/components/payments/PaymentCalendarGrid';
import FinanceFilterBar from './components/FinanceFilterBar';
import { formatCompact, formatRub } from './components/format';

/**
 * Календарь поступлений: график оплат из 1С и фактические платежи по дням.
 *
 * Раздел ничего не предсказывает — этим занят «План поступлений». Здесь
 * только то, что есть в учётной системе: какого числа партнёр обязался
 * заплатить и какого числа деньги пришли. Поэтому и цифры честно расходятся:
 * факт месяца обычно больше плана, потому что приходят и старые долги, и
 * оплата документов, выставленных уже внутри месяца.
 *
 * Просрочка по дням не раскладывается: её срок прошёл, и поставить её в
 * конкретную клетку значило бы выдумать дату. Она показана навесом над
 * календарём — сколько денег ждут сверх этого месяца и как давно.
 */
export default function PaymentCalendar({
    month,
    monthLabel,
    prevMonth,
    nextMonth,
    today,
    days = {},
    overdueThread = {},
    axis = 'partner',
    axes = [],
    breakdown = [],
    summary = {},
    filters = {},
    managers = [],
    organizations = [],
    seesAll = false,
}) {
    const [selectedDate, setSelectedDate] = useState(null);

    const go = (patch) => {
        const query = new URLSearchParams(window.location.search);

        Object.entries(patch).forEach(([key, value]) => {
            if (value === undefined || value === null || value === '') query.delete(key);
            else query.set(key, value);
        });

        setSelectedDate(null);
        router.get(`/crm/payments/calendar?${query.toString()}`, {}, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    // Шкала заливки: самый денежный день месяца задаёт максимум, остальные
    // окрашиваются относительно него — так видно ритм, а не только суммы.
    const peak = Object.values(days).reduce(
        (max, day) => Math.max(max, day.plan || 0, day.fact || 0),
        0,
    );

    // Исполнение графика — по закрытым строкам, а не по сумме платежей:
    // платёж может гасить долг прошлых месяцев, а строка этого месяца —
    // закрываться зачётом аванса. Это две разные величины.
    const executed = summary.plan > 0 ? Math.round((summary.settled / summary.plan) * 100) : null;

    const selected = selectedDate ? days[selectedDate] : null;

    return (
        <CrmLayout breadcrumbs={[{ label: 'Финансы' }, { label: 'Календарь поступлений' }]}>
            <Head title="Календарь поступлений — CRM" />

            <PageHeader
                title="Календарь поступлений"
                description="График оплат из 1С и фактические платежи по дням"
            />

            <FinanceFilterBar
                routeName="crm.payments.calendar"
                filters={filters}
                managers={managers}
                organizations={organizations}
                seesAll={seesAll}
                hidePeriod
                passthrough={['month', 'axis']}
            />

            <Box borderWidth="1px" borderRadius="lg" px={4} py={3} mb={3} bg="bg.panel">
                <Flex gap={{ base: 3, md: 6 }} wrap="wrap" align="center">
                    <Metric
                        label="Обещано в месяце"
                        value={formatRub(summary.plan)}
                        hint="Сумма строк графика оплаты из 1С, чей срок приходится на этот месяц, — включая уже закрытые. Ровно то, что учётная система назначила к оплате: без поправок на платёжную дисциплину и без просроченного с прошлых месяцев."
                    />

                    <Metric
                        label="Из них закрыто"
                        value={formatRub(summary.settled)}
                        tone="green"
                        hint="Сколько из графика этого месяца 1С уже засчитала оплаченным. Не то же самое, что сумма платежей: платёж может гасить долг прошлых месяцев, а строка месяца — закрываться зачётом аванса."
                    />

                    <Metric
                        label="Пришло денег"
                        value={formatRub(summary.fact)}
                        hint="Фактические платежи с датой внутри месяца. Обычно больше графика: приходят и старые долги, и оплата документов, выставленных уже в этом месяце. Сопоставить платёж с конкретной строкой графика 1С не позволяет — поэтому это отдельная величина, а не «исполнение» построчно."
                    />

                    {executed !== null && (
                        <Metric
                            label="Исполнение графика"
                            value={`${executed}%`}
                            tone={executed >= 80 ? 'green' : 'orange'}
                            hint={`Какая часть графика месяца уже закрыта: ${formatCompact(summary.settled)} из ${formatCompact(summary.plan)}. К сегодняшнему числу по графику должно было пройти ${formatCompact(summary.plan_to_date)} — оставшееся ещё впереди, если месяц не закончился.`}
                        />
                    )}

                    <Metric
                        label="Ждём с прошлых месяцев"
                        value={formatRub(overdueThread.total)}
                        tone="red"
                        hint="Долг, срок которого прошёл до начала этого месяца. В клетки календаря он не ставится: дата, к которой его ждали, уже была, и рисовать его сегодняшним днём значило бы выдумывать срок."
                    />
                </Flex>
            </Box>

            {overdueThread.total > 0 && (
                <Box borderWidth="1px" borderColor="red.muted" borderRadius="lg" px={4} py={3} mb={3} bg="red.subtle">
                    <HStack gap={2} mb={2} wrap="wrap">
                        <Text fontSize="sm" fontWeight="600">Навес просрочки</Text>
                        <Text fontSize="xs" color="fg.muted">
                            {formatRub(overdueThread.total)} · {overdueThread.lines} строк · самая давняя ждёт {overdueThread.oldest_days} дн.
                        </Text>
                        <MetricHint text="Эти деньги ожидались раньше и в календарь не попадают: их срок в прошлом. Полоса показывает, из какого возраста состоит долг — свежая задержка на неделю и долг, который висит больше квартала, требуют разного разговора. Разбор долга — в разделе «Просрочка»." />
                    </HStack>

                    <Flex height="10px" borderRadius="full" overflow="hidden" bg="bg.muted" mb={2}>
                        {overdueThread.buckets?.map((bucket, index) => {
                            const share = overdueThread.total > 0 ? (bucket.amount / overdueThread.total) * 100 : 0;

                            return share > 0 ? (
                                <Box
                                    key={bucket.key}
                                    width={`${share}%`}
                                    bg={THREAD_COLORS[index]}
                                    title={`${bucket.label}: ${formatRub(bucket.amount)}`}
                                />
                            ) : null;
                        })}
                    </Flex>

                    <HStack gap={4} wrap="wrap">
                        {overdueThread.buckets?.map((bucket, index) => (
                            <HStack key={bucket.key} gap={1}>
                                <Box width="8px" height="8px" borderRadius="full" bg={THREAD_COLORS[index]} />
                                <Text fontSize="10px" color="fg.muted">
                                    {bucket.label} — {formatCompact(bucket.amount)} ({bucket.count})
                                </Text>
                            </HStack>
                        ))}
                    </HStack>
                </Box>
            )}

            <Box borderWidth="1px" borderRadius="lg" p={4} mb={3}>
                <PaymentCalendarGrid
                    month={month}
                    monthLabel={monthLabel}
                    prevMonth={prevMonth}
                    nextMonth={nextMonth}
                    today={today}
                    selectedDate={selectedDate}
                    onSelectDate={setSelectedDate}
                    onChangeMonth={(value) => go({ month: value })}
                    renderCell={(date) => <DayCell day={days[date]} peak={peak} />}
                />

                <HStack gap={4} mt={3} wrap="wrap" fontSize="10px" color="fg.muted">
                    <HStack gap={1}>
                        <Box width="10px" height="10px" borderRadius="sm" bg="blue.subtle" borderWidth="1px" borderColor="blue.muted" />
                        <Text>обещано по графику</Text>
                    </HStack>
                    <HStack gap={1}>
                        <Box width="10px" height="10px" borderRadius="sm" bg="green.solid" />
                        <Text>пришло фактически</Text>
                    </HStack>
                    <Text>· высота полосы — доля от самого денежного дня месяца</Text>
                </HStack>
            </Box>

            {selected && (
                <Box borderWidth="1px" borderRadius="lg" p={4} mb={3} bg="bg.subtle">
                    <Text fontWeight="600" mb={1}>{selectedDate.split('-').reverse().join('.')}</Text>
                    <HStack gap={6} wrap="wrap">
                        <Text fontSize="sm">
                            обещано <b>{formatRub(selected.plan)}</b>
                            <Text as="span" color="fg.muted"> · {selected.plan_count} строк графика</Text>
                        </Text>
                        <Text fontSize="sm" color="green.fg">
                            пришло <b>{formatRub(selected.fact)}</b>
                            <Text as="span" color="fg.muted"> · {selected.fact_count} платежей</Text>
                        </Text>
                    </HStack>
                </Box>
            )}

            <Flex justify="space-between" align="baseline" mb={2} gap={3} wrap="wrap">
                <HStack gap={2}>
                    <Text fontWeight="600">Разрез месяца</Text>
                    <MetricHint text="Кто обещал заплатить в этом месяце, кто заплатил и сколько за ним висит с прошлых месяцев. Разрез меняет только группировку — суммы календаря выше остаются теми же." />
                </HStack>

                <HStack gap={2} wrap="wrap">
                    {axes.map((item) => (
                        <Button
                            key={item.value}
                            size="xs"
                            variant={axis === item.value ? 'solid' : 'outline'}
                            colorPalette={axis === item.value ? 'pecado' : 'gray'}
                            onClick={() => go({ axis: item.value })}
                        >
                            {item.label}
                        </Button>
                    ))}
                </HStack>
            </Flex>

            <Box borderWidth="1px" borderColor="border.muted" borderRadius="md" overflow="hidden">
                <Box overflowX="auto">
                    <Table.Root size="sm" variant="line">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeader>{axes.find((item) => item.value === axis)?.label ?? 'Строка'}</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="end">Обещано в месяце</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="end">Закрыто</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="end">Пришло денег</Table.ColumnHeader>
                                <Table.ColumnHeader width="150px">Соотношение</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="end">Ждём с прошлых</Table.ColumnHeader>
                            </Table.Row>
                        </Table.Header>

                        <Table.Body>
                            {breakdown.length === 0 && (
                                <Table.Row>
                                    <Table.Cell colSpan={6}>
                                        <Text py={8} textAlign="center" color="fg.muted">
                                            В этом месяце ни обещаний, ни платежей по текущему отбору нет
                                        </Text>
                                    </Table.Cell>
                                </Table.Row>
                            )}

                            {breakdown.map((row) => (
                                <Table.Row key={row.key} _hover={{ bg: 'bg.muted' }}>
                                    <Table.Cell>
                                        <VStack align="start" gap={0}>
                                            {row.url ? (
                                                <Box
                                                    as="a"
                                                    href={row.url}
                                                    fontSize="sm"
                                                    fontWeight="600"
                                                    _hover={{ color: 'blue.fg', textDecoration: 'underline' }}
                                                >
                                                    {row.title}
                                                </Box>
                                            ) : (
                                                <Text fontSize="sm" fontWeight="600">{row.title}</Text>
                                            )}
                                            {row.subtitle && (
                                                <Text fontSize="10px" color="fg.muted">{row.subtitle}</Text>
                                            )}
                                        </VStack>
                                    </Table.Cell>

                                    <Table.Cell textAlign="end">
                                        <Text fontSize="sm" whiteSpace="nowrap">{formatRub(row.plan)}</Text>
                                        {row.plan_count > 0 && (
                                            <Text fontSize="10px" color="fg.muted">{row.plan_count} строк</Text>
                                        )}
                                    </Table.Cell>

                                    <Table.Cell textAlign="end">
                                        <Text fontSize="sm" color={row.settled > 0 ? 'green.fg' : 'fg.muted'} whiteSpace="nowrap">
                                            {formatRub(row.settled)}
                                        </Text>
                                        {row.plan > 0 && (
                                            <Text fontSize="10px" color="fg.muted">
                                                {Math.round((row.settled / row.plan) * 100)}% графика
                                            </Text>
                                        )}
                                    </Table.Cell>

                                    <Table.Cell textAlign="end">
                                        <Text fontSize="sm" fontWeight="600" whiteSpace="nowrap">
                                            {formatRub(row.fact)}
                                        </Text>
                                        {row.fact_count > 0 && (
                                            <Text fontSize="10px" color="fg.muted">{row.fact_count} платежей</Text>
                                        )}
                                    </Table.Cell>

                                    <Table.Cell>
                                                                <PlanFactBar plan={row.plan} fact={row.fact} />
                                    </Table.Cell>

                                    <Table.Cell textAlign="end">
                                        <Text fontSize="sm" color={row.overdue > 0 ? 'red.fg' : 'fg.muted'} whiteSpace="nowrap">
                                            {row.overdue > 0 ? formatRub(row.overdue) : '—'}
                                        </Text>
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </Table.Root>
                </Box>
            </Box>
        </CrmLayout>
    );
}

/** Цвета навеса: от свежей задержки к застарелому долгу. */
const THREAD_COLORS = ['orange.solid', 'orange.600', 'red.solid', 'red.700'];

/**
 * Клетка дня: две полосы — обещано и пришло.
 *
 * Полосами, а не числами: суммы в тридцати клетках сливаются, а разница высот
 * читается мгновенно — видно и ритм оплат, и дни, когда деньги не пришли.
 */
function DayCell({ day, peak }) {
    if (! day || (day.plan <= 0 && day.fact <= 0)) {
        return null;
    }

    const height = (value) => (peak > 0 ? Math.max(value > 0 ? 3 : 0, Math.round((value / peak) * 34)) : 0);

    return (
        <VStack align="stretch" gap={1} mt={1}>
            <Flex align="flex-end" gap={1} height="36px">
                <Box flex="1" bg="blue.subtle" borderWidth="1px" borderColor="blue.muted" borderRadius="sm" height={`${height(day.plan)}px`} />
                <Box flex="1" bg="green.solid" borderRadius="sm" height={`${height(day.fact)}px`} />
            </Flex>

            <VStack align="start" gap={0}>
                {day.plan > 0 && (
                    <Text fontSize="10px" color="fg.muted" lineHeight="1.2">
                        {formatCompact(day.plan)}
                        {day.settled > 0 && day.settled < day.plan ? ` · закрыто ${Math.round((day.settled / day.plan) * 100)}%` : ''}
                        {day.settled >= day.plan ? ' · закрыт' : ''}
                    </Text>
                )}
                {day.fact > 0 && (
                    <Text fontSize="10px" color="green.fg" fontWeight="600" lineHeight="1.2">{formatCompact(day.fact)}</Text>
                )}
            </VStack>
        </VStack>
    );
}

/** Соотношение «обещано / пришло» одной полосой. */
function PlanFactBar({ plan, fact }) {
    const max = Math.max(plan, fact, 1);

    return (
        <VStack align="stretch" gap={1}>
            <Box bg="bg.muted" borderRadius="full" height="5px" overflow="hidden">
                <Box bg="blue.solid" height="5px" width={`${Math.round((plan / max) * 100)}%`} />
            </Box>
            <Box bg="bg.muted" borderRadius="full" height="5px" overflow="hidden">
                <Box bg="green.solid" height="5px" width={`${Math.round((fact / max) * 100)}%`} />
            </Box>
        </VStack>
    );
}

const Metric = ({ label, value, tone, hint }) => (
    <VStack align="start" gap={0}>
        <HStack gap={1}>
            <Text fontSize="10px" color="fg.muted" textTransform="uppercase" letterSpacing="0.03em">{label}</Text>
            {hint && <MetricHint text={hint} />}
        </HStack>
        <Text
            fontSize="lg"
            fontWeight="700"
            whiteSpace="nowrap"
            color={tone === 'green' ? 'green.fg' : tone === 'red' ? 'red.fg' : tone === 'orange' ? 'orange.fg' : undefined}
        >
            {value}
        </Text>
    </VStack>
);
