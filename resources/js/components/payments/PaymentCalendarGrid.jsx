import { useMemo } from 'react';
import { Box, Flex, HStack, Text, SimpleGrid } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { LuChevronLeft, LuChevronRight } from 'react-icons/lu';

const WEEKDAYS = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

export const formatMoney = (value) => Number(value || 0)
    .toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * Месячная сетка календаря оплат.
 *
 * Общая для кабинета клиента и CRM: разница между ними — только в содержимом
 * ячейки, поэтому оно приходит функцией renderCell, а не флагом режима.
 * Даты приходят строками YYYY-MM-DD и сравниваются как строки — new Date()
 * на такой строке трактует её как UTC и на московском времени сдвигает день назад.
 */
export default function PaymentCalendarGrid({
    month,
    monthLabel,
    prevMonth,
    nextMonth,
    today,
    selectedDate,
    onSelectDate,
    onChangeMonth,
    renderCell,
}) {
    const cells = useMemo(() => buildMonthCells(month), [month]);

    return (
        <Box>
            <Flex align="center" justify="space-between" mb="3" gap="2" wrap="wrap">
                <HStack gap="1">
                    <Button
                        size="sm"
                        variant="outline"
                        aria-label="Предыдущий месяц"
                        onClick={() => onChangeMonth(prevMonth)}
                    >
                        <LuChevronLeft size={16} />
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        aria-label="Следующий месяц"
                        onClick={() => onChangeMonth(nextMonth)}
                    >
                        <LuChevronRight size={16} />
                    </Button>
                </HStack>

                <Text fontWeight="semibold" fontSize="lg">{monthLabel}</Text>

                <Button size="sm" variant="ghost" onClick={() => onChangeMonth(today.slice(0, 7))}>
                    Текущий месяц
                </Button>
            </Flex>

            <SimpleGrid columns={7} gap="1" mb="1">
                {WEEKDAYS.map((day) => (
                    <Text key={day} fontSize="xs" color="fg.muted" textAlign="center" fontWeight="medium">
                        {day}
                    </Text>
                ))}
            </SimpleGrid>

            <SimpleGrid columns={7} gap="1">
                {cells.map((date, index) => {
                    if (!date) {
                        return <Box key={`empty-${index}`} minH="20" />;
                    }

                    const isToday = date === today;
                    const isSelected = date === selectedDate;

                    return (
                        <Box
                            key={date}
                            as="button"
                            type="button"
                            textAlign="left"
                            minH="20"
                            p="1.5"
                            borderRadius="md"
                            border="1px solid"
                            borderColor={isSelected ? 'pecado.solid' : (isToday ? 'fg.muted' : 'border.muted')}
                            bg={isSelected ? 'pecado.subtle' : 'bg'}
                            onClick={() => onSelectDate(isSelected ? null : date)}
                            _hover={{ borderColor: 'pecado.solid' }}
                        >
                            <Text fontSize="xs" color={isToday ? 'fg' : 'fg.muted'} fontWeight={isToday ? 'bold' : 'normal'}>
                                {Number(date.slice(8, 10))}
                            </Text>
                            {renderCell(date)}
                        </Box>
                    );
                })}
            </SimpleGrid>
        </Box>
    );
}

/**
 * Ячейки месяца, дополненные пустыми до начала недели (понедельник — первый).
 *
 * Считаем через UTC-конструктор: локальная полночь в отрицательных смещениях
 * съезжает на предыдущие сутки, и первое число уезжало бы в чужую колонку.
 */
function buildMonthCells(month) {
    const [year, monthIndex] = month.split('-').map(Number);
    const firstDay = new Date(Date.UTC(year, monthIndex - 1, 1));
    const daysInMonth = new Date(Date.UTC(year, monthIndex, 0)).getUTCDate();

    // getUTCDay(): 0 — воскресенье, а неделя начинается с понедельника.
    const leading = (firstDay.getUTCDay() + 6) % 7;

    const cells = Array.from({ length: leading }, () => null);

    for (let day = 1; day <= daysInMonth; day += 1) {
        cells.push(`${month}-${String(day).padStart(2, '0')}`);
    }

    return cells;
}
