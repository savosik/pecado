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

    // «в августе», а не «в август»: заголовок колонки — часть фразы.
    const monthInCase = MONTHS_IN[Number((month ?? '').slice(5, 7)) - 1] ?? 'этом месяце';

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

            {/* Объяснение на самой странице, а не в подсказках: раздел
                показывает три разные величины, и без пары фраз они читаются
                как три варианта одного числа, которые почему-то не сходятся. */}
            <Box borderWidth="1px" borderRadius="lg" px={4} py={3} mb={3} bg="blue.subtle" borderColor="blue.muted">
                <Text fontSize="sm" fontWeight="600" mb={1}>Что показывает раздел</Text>
                <VStack align="start" gap={0} fontSize="xs" color="fg.muted">
                    <Text>
                        <b>Ждём</b> — какого числа партнёры обязались заплатить по графику из 1С.
                        Это обязательство из учётной системы, без наших поправок и оценок.
                    </Text>
                    <Text>
                        <b>Пришло</b> — какого числа деньги реально поступили. С графиком месяца эта
                        сумма не обязана совпадать: платят и по старым долгам, и по документам,
                        выставленным уже внутри месяца.
                    </Text>
                    <Text>
                        <b>Долг с прошлых месяцев</b> — то, что ждали раньше и не дождались. В дни
                        календаря он не ставится: его срок уже прошёл, и приписать его к какому-то
                        числу было бы выдумкой. Он показан отдельной полосой ниже.
                    </Text>
                </VStack>
            </Box>

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
                    renderCell={(date) => (
                        <DayCell day={days[date]} peak={peak} isPast={date < today} />
                    )}
                />

                <Text fontSize="xs" color="fg.muted" mt={3}>
                    В каждом дне две строки: <b>ждём</b> — сколько должны были заплатить по графику
                    именно в этот день, <b>пришло</b> — сколько денег в этот день поступило.
                    Длина заливки показывает размер суммы относительно самого денежного дня месяца.
                    Красная подпись «не заплатили» — день прошёл, деньги ждали, но их не было.
                    Нажмите на день, чтобы увидеть подробности.
                </Text>
            </Box>

            {selected && (
                <Box borderWidth="1px" borderRadius="lg" p={4} mb={3} bg="bg.subtle">
                    <Text fontWeight="600" mb={2}>{selectedDate.split('-').reverse().join('.')}</Text>

                    <VStack align="start" gap={1} fontSize="sm">
                        <Text>
                            По графику в этот день ждали <b>{formatRub(selected.plan)}</b>
                            {selected.plan_count > 0 && (
                                <Text as="span" color="fg.muted">
                                    {' '}— {selected.plan_count} {pluralLines(selected.plan_count)} оплаты
                                </Text>
                            )}
                        </Text>

                        <Text color={selected.fact > 0 ? 'green.fg' : 'red.fg'}>
                            {selected.fact > 0 ? (
                                <>
                                    Пришло <b>{formatRub(selected.fact)}</b>
                                    <Text as="span" color="fg.muted">
                                        {' '}— {selected.fact_count} {pluralPayments(selected.fact_count)}
                                    </Text>
                                </>
                            ) : (
                                <>Денег в этот день не поступало</>
                            )}
                        </Text>

                        {selected.plan > 0 && (
                            <Text fontSize="xs" color="fg.muted">
                                {selected.settled >= selected.plan
                                    ? 'Все обязательства этого дня 1С уже закрыла.'
                                    : `Из обязательств дня закрыто ${formatRub(selected.settled)} — осталось ${formatRub(selected.plan - selected.settled)}.`}
                            </Text>
                        )}
                    </VStack>
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
                                <Table.ColumnHeader textAlign="end">
                                    <ColumnLabel
                                        label={`Должен был заплатить в ${monthInCase}`}
                                        hint="Сумма по графику оплаты из 1С со сроком внутри этого месяца — вместе с теми строками, которые уже оплачены. Это обязательство из учётной системы, а не наша оценка."
                                    />
                                </Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="end">
                                    <ColumnLabel
                                        label="Уже оплачено"
                                        hint="Какую часть обязательства 1С отметила оплаченной. Может закрываться не только платежом, но и зачётом аванса — поэтому это отдельная колонка от «поступило денег»."
                                    />
                                </Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="end">
                                    <ColumnLabel
                                        label="Осталось оплатить"
                                        hint="Незакрытая часть обязательств этого месяца: сколько ещё должны заплатить по графику до конца месяца."
                                    />
                                </Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="end">
                                    <ColumnLabel
                                        label="Поступило денег"
                                        hint="Сколько денег реально пришло в этом месяце — по всем платежам, включая оплату долгов прошлых месяцев и документов, выставленных уже внутри месяца. Поэтому число может быть больше или меньше обязательства слева: это не «выполнение плана», а отдельный факт."
                                    />
                                </Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="end">
                                    <ColumnLabel
                                        label="Долг с прошлых месяцев"
                                        hint="Просроченное: срок был раньше этого месяца, деньги так и не пришли. В обязательства месяца не входит, разбирается в разделе «Просрочка»."
                                    />
                                </Table.ColumnHeader>
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
                                            <Text fontSize="10px" color="fg.muted">
                                                {row.plan_count} {pluralLines(row.plan_count)} графика
                                            </Text>
                                        )}
                                    </Table.Cell>

                                    <Table.Cell textAlign="end">
                                        <Text fontSize="sm" color={row.settled > 0 ? 'green.fg' : 'fg.muted'} whiteSpace="nowrap">
                                            {formatRub(row.settled)}
                                        </Text>
                                        {row.plan > 0 && (
                                            <Text fontSize="10px" color="fg.muted">
                                                это {Math.round((row.settled / row.plan) * 100)}% от обязательства
                                            </Text>
                                        )}
                                    </Table.Cell>

                                    <Table.Cell textAlign="end">
                                        {row.plan - row.settled > 1 ? (
                                            <>
                                                <Text fontSize="sm" fontWeight="600" color="orange.fg" whiteSpace="nowrap">
                                                    {formatRub(row.plan - row.settled)}
                                                </Text>
                                                <Text fontSize="10px" color="fg.muted">ещё ждём в этом месяце</Text>
                                            </>
                                        ) : (
                                            <Text fontSize="sm" color="green.fg" whiteSpace="nowrap">рассчитались</Text>
                                        )}
                                    </Table.Cell>

                                    <Table.Cell textAlign="end">
                                        <Text fontSize="sm" fontWeight="600" whiteSpace="nowrap">
                                            {formatRub(row.fact)}
                                        </Text>
                                        <Text fontSize="10px" color="fg.muted">
                                            {row.fact_count > 0
                                                ? `${row.fact_count} ${pluralPayments(row.fact_count)}`
                                                : 'платежей не было'}
                                        </Text>
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

/** Заголовок колонки с пояснением: цифра без подписи читается как шум. */
const ColumnLabel = ({ label, hint }) => (
    <HStack gap={1} justify="inherit">
        <Text fontSize="xs">{label}</Text>
        <MetricHint text={hint} />
    </HStack>
);

const pluralLines = (count) => {
    const tail = count % 10;
    const teen = count % 100 >= 11 && count % 100 <= 14;

    if (! teen && tail === 1) return 'строка';
    if (! teen && tail >= 2 && tail <= 4) return 'строки';

    return 'строк';
};

const pluralPayments = (count) => {
    const tail = count % 10;
    const teen = count % 100 >= 11 && count % 100 <= 14;

    if (! teen && tail === 1) return 'платёж';
    if (! teen && tail >= 2 && tail <= 4) return 'платежа';

    return 'платежей';
};

/** Месяцы в предложном падеже: заголовок колонки — часть фразы, а не ярлык. */
const MONTHS_IN = [
    'январе', 'феврале', 'марте', 'апреле', 'мае', 'июне',
    'июле', 'августе', 'сентябре', 'октябре', 'ноябре', 'декабре',
];

/** Цвета навеса: от свежей задержки к застарелому долгу. */
const THREAD_COLORS = ['orange.solid', 'orange.600', 'red.solid', 'red.700'];

/**
 * Клетка дня: две подписанные строки — сколько ждали и сколько пришло.
 *
 * Раньше здесь были две безымянные полосы: угадать, какая из них план, а
 * какая факт, было нельзя, и календарь читался как узор. Теперь у каждой
 * цифры есть слово, а полоска осталась только фоном под ним.
 */
function DayCell({ day, peak, isPast }) {
    if (! day || (day.plan <= 0 && day.fact <= 0)) {
        return null;
    }

    const width = (value) => (peak > 0 ? Math.max(value > 0 ? 6 : 0, Math.round((value / peak) * 100)) : 0);

    // День прошёл, деньги ждали, но не пришли — единственное состояние, ради
    // которого календарь вообще открывают.
    const missed = isPast && day.plan > 0 && day.fact <= 0;

    return (
        <VStack align="stretch" gap="2px" mt={1}>
            {day.plan > 0 && (
                <Box position="relative" borderRadius="sm" overflow="hidden" px="3px" py="1px">
                    <Box position="absolute" inset="0" bg="blue.subtle" width={`${width(day.plan)}%`} />
                    <HStack position="relative" gap={1} justify="space-between">
                        <Text fontSize="9px" color="fg.muted">ждём</Text>
                        <Text fontSize="10px" fontWeight="600">{formatCompact(day.plan)}</Text>
                    </HStack>
                </Box>
            )}

            {day.fact > 0 && (
                <Box position="relative" borderRadius="sm" overflow="hidden" px="3px" py="1px">
                    <Box position="absolute" inset="0" bg="green.subtle" width={`${width(day.fact)}%`} />
                    <HStack position="relative" gap={1} justify="space-between">
                        <Text fontSize="9px" color="green.fg">пришло</Text>
                        <Text fontSize="10px" fontWeight="700" color="green.fg">{formatCompact(day.fact)}</Text>
                    </HStack>
                </Box>
            )}

            {missed && (
                <Text fontSize="9px" color="red.fg" fontWeight="600">не заплатили</Text>
            )}
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
