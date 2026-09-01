import { Box, HStack, Text, VStack } from '@chakra-ui/react';
import PaymentCalendarGrid from '@/components/payments/PaymentCalendarGrid';
import { formatCompact } from './format';

/**
 * Месяц графика: сколько ждём и сколько пришло по дням.
 *
 * В клетке две величины: сколько должны заплатить по графику отгруженного и
 * сколько денег пришло. Совпадать они не обязаны — платят и по старым долгам,
 * и по документам, выставленным уже внутри месяца.
 *
 * Дни правее горизонта графика приглушены и подписаны: пустая клетка там
 * означает «1С ещё не прислала строки», а не «денег не ждут».
 */
export default function PlanCalendar({
    calendar,
    today,
    horizon,
    selectedDay,
    onSelectDay,
    onChangeMonth,
}) {
    const days = calendar.days ?? {};

    // Шкала заливки: самый денежный день месяца задаёт максимум, остальные
    // окрашиваются относительно него — так виден ритм, а не только суммы.
    const peak = Object.values(days).reduce(
        (max, day) => Math.max(max, day.plan || 0, day.fact || 0),
        0,
    );

    return (
        <Box borderWidth="1px" borderRadius="lg" p={4} mb={3}>
            <PaymentCalendarGrid
                month={calendar.month}
                monthLabel={calendar.month_label}
                prevMonth={calendar.prev_month}
                nextMonth={calendar.next_month}
                today={today}
                selectedDate={selectedDay}
                onSelectDate={onSelectDay}
                onChangeMonth={onChangeMonth}
                renderCell={(date) => (
                    <DayCell
                        day={days[date]}
                        peak={peak}
                        isPast={date < today}
                        beyondHorizon={horizon !== null && horizon !== undefined && date > horizon}
                    />
                )}
            />

            <Text fontSize="xs" color="fg.muted" mt={3}>
                В клетке: <b>ждём</b> — сколько ещё должны заплатить по графику этого дня,
                <b> пришло</b> — сколько денег поступило. На прошедших днях сверху видно,
                сколько всего стояло <b>по графику</b> и какая часть закрыта, а «ждём»
                называется «осталось». Нажмите на день, чтобы увидеть, кто и по каким
                реализациям.
            </Text>
        </Box>
    );
}

function DayCell({ day, peak, isPast, beyondHorizon }) {
    if (beyondHorizon) {
        return (
            <VStack align="stretch" gap={0} opacity={0.35}>
                <Text fontSize="9px" color="fg.muted">графика нет</Text>
            </VStack>
        );
    }

    if (!day) return null;

    const planWidth = peak > 0 ? Math.max(2, (day.plan / peak) * 100) : 0;
    const factWidth = peak > 0 ? Math.max(2, (day.fact / peak) * 100) : 0;
    const missed = isPast && day.plan > 0 && day.fact <= 0;
    const scheduled = day.scheduled ?? 0;
    // Исполнение показывается только на прошедших днях: у будущего дня срок
    // ещё не наступил, и «закрыто 0 %» там читалось бы как тревога.
    const executed = isPast && scheduled > 0 ? Math.round((day.settled / scheduled) * 100) : null;

    return (
        <VStack align="stretch" gap="2px">
            {isPast && scheduled > 0 && (
                <HStack gap={1} justify="space-between">
                    <Text fontSize="9px" color="fg.muted">по графику</Text>
                    <Text fontSize="9px" color="fg.muted">
                        {formatCompact(scheduled)} · {executed}%
                    </Text>
                </HStack>
            )}

            {day.plan > 0 && (
                <Box>
                    <HStack gap={1} justify="space-between">
                        <Text fontSize="9px" color="fg.muted">{isPast ? 'осталось' : 'ждём'}</Text>
                        <Text fontSize="10px" fontWeight="600">{formatCompact(day.plan)}</Text>
                    </HStack>
                    <Box bg="bg.muted" borderRadius="full" height="3px" overflow="hidden">
                        <Box bg={missed ? 'red.solid' : 'blue.solid'} height="3px" width={`${planWidth}%`} />
                    </Box>
                </Box>
            )}

            {day.fact !== 0 && (
                <Box>
                    <HStack gap={1} justify="space-between">
                        <Text fontSize="9px" color="fg.muted">пришло</Text>
                        <Text fontSize="10px" fontWeight="600" color={day.fact > 0 ? 'green.fg' : 'red.fg'}>
                            {formatCompact(day.fact)}
                        </Text>
                    </HStack>
                    <Box bg="bg.muted" borderRadius="full" height="3px" overflow="hidden">
                        <Box bg="green.solid" height="3px" width={`${factWidth}%`} />
                    </Box>
                </Box>
            )}

            {missed && (
                <Text fontSize="9px" color="red.fg">не заплатили</Text>
            )}
        </VStack>
    );
}
