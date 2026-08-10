import { useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Badge, Box, Flex, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { LuList, LuCalendarClock, LuTriangleAlert } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import MultiSelectFilter from '@/Crm/Components/MultiSelectFilter';
import PaymentCalendarGrid, { formatMoney } from '@/components/payments/PaymentCalendarGrid';

/**
 * Календарь поступления денег.
 *
 * План — остатки по графику оплаты реализаций («Правила оплаты» 1С),
 * факт — проведённые платежи по их бизнес-дате. Разрез по менеджерам работает
 * тем же скоупом партнёров, что и журналы, поэтому фильтр здесь только один.
 */
export default function PaymentCalendar({
    month,
    monthLabel,
    prevMonth,
    nextMonth,
    today,
    entries = [],
    overdueEntries = [],
    facts = {},
    summary = {},
    managers = [],
    seesAll = false,
    filters = {},
}) {
    const [selectedDate, setSelectedDate] = useState(null);

    const planByDate = useMemo(() => {
        const map = new Map();

        entries.forEach((entry) => {
            const bucket = map.get(entry.due_date) || { amount: 0, overdue: false, items: [] };
            // Суммируем рубли, а не сумму документа: в месяце могут встретиться
            // валютные реализации, и сложение «как есть» дало бы бессмысленный итог.
            bucket.amount += entry.unpaid_rub;
            bucket.overdue = bucket.overdue || entry.is_overdue;
            bucket.items.push(entry);
            map.set(entry.due_date, bucket);
        });

        return map;
    }, [entries]);

    const navigate = (params) => {
        setSelectedDate(null);
        router.get('/crm/payments/calendar', { month, ...filters, ...params }, {
            preserveState: true,
            replace: true,
        });
    };

    const selected = selectedDate
        ? { plan: planByDate.get(selectedDate), fact: facts[selectedDate] }
        : null;

    return (
        <>
            <Head title="CRM — Календарь поступлений" />
            <PageHeader
                title="Календарь поступлений"
                description="План по графику оплаты из 1С и фактические поступления по дням"
            />

            <VStack align="stretch" gap={3} mb={4}>
                <HStack gap={2} wrap="wrap">
                    <Button size="sm" variant="outline" onClick={() => router.get('/crm/payments')}>
                        <LuList size={16} /> Журнал
                    </Button>
                    <Button size="sm" variant="solid" colorPalette="pecado">
                        <LuCalendarClock size={16} /> Календарь поступлений
                    </Button>
                </HStack>

                {seesAll && managers.length > 0 && (
                    <Flex gap={3} wrap="wrap">
                        <MultiSelectFilter
                            label="Менеджер"
                            options={managers}
                            selectedIds={filters.manager_ids || []}
                            onChange={(value) => navigate({ manager_ids: value })}
                        />
                    </Flex>
                )}
            </VStack>

            <SimpleGrid columns={{ base: 1, md: 3 }} gap={3} mb={4}>
                <Tile label={`План: ${monthLabel.toLowerCase()}`} value={formatMoney(summary.plan_month)} hint="Остаток к поступлению по графику" />
                <Tile label={`Факт: ${monthLabel.toLowerCase()}`} value={formatMoney(summary.fact_month)} hint="Поступления минус возвраты" />
                <Tile
                    label="Просрочено"
                    value={formatMoney(summary.overdue_amount)}
                    hint={summary.overdue_count ? `Документов: ${summary.overdue_count}` : 'Просрочки нет'}
                    tone={summary.overdue_amount > 0 ? 'red' : undefined}
                />
            </SimpleGrid>

            <Box p={4} mb={4} borderWidth="1px" borderColor="border.muted" borderRadius="xl" bg="bg">
                <PaymentCalendarGrid
                    month={month}
                    monthLabel={monthLabel}
                    prevMonth={prevMonth}
                    nextMonth={nextMonth}
                    today={today}
                    selectedDate={selectedDate}
                    onSelectDate={setSelectedDate}
                    onChangeMonth={(next) => navigate({ month: next })}
                    renderCell={(date) => {
                        const plan = planByDate.get(date);
                        const fact = facts[date];

                        if (!plan && !fact) {
                            return null;
                        }

                        return (
                            <VStack align="stretch" gap={0} mt={1}>
                                {plan && (
                                    <Text fontSize="2xs" color={plan.overdue ? 'red.fg' : 'fg.muted'}>
                                        план {formatMoney(plan.amount)}
                                    </Text>
                                )}
                                {fact && (
                                    <Text fontSize="2xs" fontWeight="semibold" color="green.fg">
                                        факт {formatMoney(fact.amount)}
                                    </Text>
                                )}
                            </VStack>
                        );
                    }}
                />
            </Box>

            {selected && (
                <Box p={4} mb={4} borderWidth="1px" borderColor="border.muted" borderRadius="xl" bg="bg">
                    <Text fontWeight="semibold" mb={3}>
                        {selectedDate.split('-').reverse().join('.')}
                        {selected.fact && ` · фактически поступило ${formatMoney(selected.fact.amount)} (документов: ${selected.fact.count})`}
                    </Text>

                    {selected.plan ? (
                        <VStack align="stretch" gap={2}>
                            {selected.plan.items.map((entry) => (
                                <EntryRow key={entry.key} entry={entry} />
                            ))}
                        </VStack>
                    ) : (
                        <Text fontSize="sm" color="fg.muted">Плановых поступлений на этот день нет.</Text>
                    )}
                </Box>
            )}

            {overdueEntries.length > 0 && (
                <Box p={4} borderWidth="1px" borderColor="red.muted" borderRadius="xl" bg="bg">
                    <HStack gap={2} mb={3}>
                        <LuTriangleAlert size={16} />
                        <Text fontWeight="semibold">Просроченные поступления</Text>
                    </HStack>
                    <VStack align="stretch" gap={2}>
                        {overdueEntries.map((entry) => (
                            <EntryRow key={entry.key} entry={entry} showDate />
                        ))}
                    </VStack>
                </Box>
            )}

            {entries.length === 0 && overdueEntries.length === 0 && (
                <Box p={6} borderWidth="1px" borderColor="border.muted" borderRadius="xl" bg="bg">
                    <Text color="fg.muted" textAlign="center">
                        Плановых поступлений за этот месяц нет. График оплаты приходит из 1С
                        вместе с реализацией — по документам без «Правил оплаты» календарь пуст.
                    </Text>
                </Box>
            )}
        </>
    );
}

function Tile({ label, value, hint, tone }) {
    return (
        <Box p={4} borderWidth="1px" borderColor={tone === 'red' ? 'red.muted' : 'border.muted'} borderRadius="xl" bg="bg">
            <Text fontSize="xs" color="fg.muted" mb={1}>{label}</Text>
            <Text fontSize="xl" fontWeight="bold" color={tone === 'red' ? 'red.fg' : 'fg'}>{value}</Text>
            <Text fontSize="xs" color="fg.muted" mt={1}>{hint}</Text>
        </Box>
    );
}

function EntryRow({ entry, showDate = false }) {
    return (
        <Flex
            justify="space-between"
            align={{ base: 'start', sm: 'center' }}
            direction={{ base: 'column', sm: 'row' }}
            gap={2}
            p={3}
            borderWidth="1px"
            borderColor="border.muted"
            borderRadius="lg"
        >
            <VStack align="start" gap={0.5}>
                <HStack gap={2} wrap="wrap">
                    <Text fontWeight="medium">{entry.client.name}</Text>
                    {entry.is_overdue && (
                        <Badge colorPalette="red" size="sm">
                            Просрочено на {entry.days_overdue} дн.
                        </Badge>
                    )}
                </HStack>
                <Text fontSize="xs" color="fg.muted">
                    {showDate && `Срок ${entry.due_date_label} · `}
                    Реализация {entry.shipment.number}
                    {entry.stage_name && ` · ${entry.stage_name}`}
                </Text>
            </VStack>

            <HStack gap={3}>
                <Text fontWeight="semibold" whiteSpace="nowrap">{entry.unpaid_label}</Text>
                <Button size="xs" variant="ghost" onClick={() => router.visit(entry.shipment.url)}>
                    Реализация
                </Button>
                <Button size="xs" variant="ghost" onClick={() => router.visit(entry.client.url)}>
                    Партнёр
                </Button>
            </HStack>
        </Flex>
    );
}

PaymentCalendar.layout = (page) => <CrmLayout>{page}</CrmLayout>;
