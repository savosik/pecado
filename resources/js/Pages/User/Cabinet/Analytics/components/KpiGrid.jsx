import { Box, SimpleGrid, HStack, VStack, Text, Heading } from '@chakra-ui/react';
import {
    LuPackage, LuBanknote, LuTrendingUp, LuLayers, LuUsers,
} from 'react-icons/lu';

const fmtInt = (v) => Number(v ?? 0).toLocaleString('ru-RU');
const fmtMoney = (v) => Number(v ?? 0).toLocaleString('ru-RU', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

export default function KpiGrid({ metrics, currency }) {
    const symbol = currency?.symbol || '₽';

    const cards = [
        { title: 'Количество поставок', value: fmtInt(metrics.shipments_count), icon: LuPackage, color: 'blue' },
        { title: 'Сумма', value: `${fmtMoney(metrics.total_amount)} ${symbol}`, icon: LuBanknote, color: 'green' },
        { title: 'Средний чек', value: `${fmtMoney(metrics.avg_check)} ${symbol}`, icon: LuTrendingUp, color: 'purple' },
        { title: 'Штук', value: fmtInt(metrics.items_total_qty), icon: LuLayers, color: 'orange' },
        { title: 'Контрагентов', value: fmtInt(metrics.contractors_count), icon: LuUsers, color: 'teal' },
    ];

    return (
        <SimpleGrid columns={{ base: 2, md: 3, lg: 5 }} gap={4}>
            {cards.map((c) => (
                <Box
                    key={c.title}
                    bg="bg.panel"
                    borderRadius="xl"
                    borderWidth="1px"
                    borderColor="border"
                    p={4}
                    boxShadow="sm"
                    transition="box-shadow 0.2s"
                    _hover={{ boxShadow: 'md' }}
                >
                    <HStack justify="space-between" align="start">
                        <VStack align="start" gap={1} flex="1" minW="0">
                            <Text fontSize="xs" color="fg.muted" fontWeight="500" lineClamp={1}>
                                {c.title}
                            </Text>
                            <Heading size={{ base: 'md', md: 'lg' }} color="fg" lineClamp={1}>
                                {c.value}
                            </Heading>
                        </VStack>
                        <Box
                            p={2}
                            bg={`${c.color}.subtle`}
                            color={`${c.color}.fg`}
                            borderRadius="lg"
                            display={{ base: 'none', md: 'block' }}
                        >
                            <c.icon size={18} />
                        </Box>
                    </HStack>
                </Box>
            ))}
        </SimpleGrid>
    );
}
