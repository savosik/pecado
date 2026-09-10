import { Box, Collapsible, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { LuChevronDown, LuTrendingUp } from 'react-icons/lu';
import { fmtRub, fmtSigned } from '../../Salary/components/format';

/**
 * Прогноз на конец месяца — вилка, свёрнутая по умолчанию.
 *
 * Три числа: «если ничего не менять» (заработано сейчас), «если так и пойдёт»
 * (тот же темп до конца месяца) и «если сделать перечисленное» (плюс рычаги).
 * Все три посчитаны сервером той же формулой на гипотетических входах.
 */
export default function ForecastRange({ forecast, total }) {
    if (!forecast) {
        return null;
    }

    const cells = [
        { key: 'low', label: 'Если ничего не менять', value: forecast.low, tone: 'gray' },
        { key: 'expected', label: 'Если так и пойдёт', value: forecast.expected, tone: 'blue' },
        { key: 'high', label: 'Если сделать перечисленное', value: forecast.high, tone: 'green' },
    ];

    return (
        <Collapsible.Root bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflow="hidden">
            <Collapsible.Trigger asChild>
                <HStack as="button" type="button" w="100%" gap={3} p={4} textAlign="left" cursor="pointer" _hover={{ bg: 'bg.subtle' }}>
                    <Box p={2} borderRadius="lg" bg="blue.subtle" color="blue.fg" display="flex" flexShrink={0}>
                        <LuTrendingUp size={18} />
                    </Box>
                    <Box flex="1" minW={0}>
                        <Text fontWeight="700" fontSize="sm">Прогноз на конец месяца</Text>
                        <Text fontSize="xs" color="fg.muted">
                            Прошло {forecast.days_passed} из {forecast.days_total} рабочих дней · ожидаемо {fmtRub(forecast.expected, 0)}
                        </Text>
                    </Box>
                    <Box color="fg.subtle"><LuChevronDown size={18} /></Box>
                </HStack>
            </Collapsible.Trigger>
            <Collapsible.Content>
                <SimpleGrid columns={{ base: 1, sm: 3 }} gap={3} px={4} pb={4}>
                    {cells.map((cell) => (
                        <VStack key={cell.key} align="start" gap={0} p={3} borderRadius="lg" bg={`${cell.tone}.subtle`}>
                            <Text fontSize="xs" color="fg.muted">{cell.label}</Text>
                            <Text fontWeight="800" fontSize="xl" fontVariantNumeric="tabular-nums">{fmtRub(cell.value, 0)}</Text>
                            {cell.key !== 'low' && (
                                <Text fontSize="xs" color="fg.subtle">{fmtSigned(cell.value - total)} к текущему</Text>
                            )}
                        </VStack>
                    ))}
                </SimpleGrid>
            </Collapsible.Content>
        </Collapsible.Root>
    );
}
