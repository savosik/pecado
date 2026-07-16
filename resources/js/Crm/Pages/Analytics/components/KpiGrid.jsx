import { Box, SimpleGrid, HStack, VStack, Text, Heading, Badge } from '@chakra-ui/react';
import {
    LuPackage, LuBanknote, LuTrendingUp, LuLayers, LuUsers,
    LuArrowUpRight, LuArrowDownRight, LuMinus,
} from 'react-icons/lu';

const fmtInt = (v) => Number(v ?? 0).toLocaleString('ru-RU');
const fmtMoney = (v) => Number(v ?? 0).toLocaleString('ru-RU', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

// Для суммы/штук/чека/контрагентов рост — это «хорошо» (зелёный).
function DeltaBadge({ delta }) {
    if (!delta) return null;
    const { pct } = delta;

    // pct === null → в прошлом периоде было 0 (новое), pct === 0 → без изменений.
    if (pct === null) {
        return <Badge size="sm" colorPalette="green" variant="subtle"><LuArrowUpRight /> новое</Badge>;
    }
    if (pct === 0) {
        return <Badge size="sm" colorPalette="gray" variant="subtle"><LuMinus /> 0%</Badge>;
    }

    const up = pct > 0;
    return (
        <Badge size="sm" colorPalette={up ? 'green' : 'red'} variant="subtle">
            {up ? <LuArrowUpRight /> : <LuArrowDownRight />}
            {up ? '+' : ''}{Number(pct).toLocaleString('ru-RU', { maximumFractionDigits: 1 })}%
        </Badge>
    );
}

export default function KpiGrid({ metrics, currency, deltas = null }) {
    const symbol = currency?.symbol || '₽';

    const cards = [
        { key: 'shipments_count', title: 'Количество поставок', value: fmtInt(metrics.shipments_count), icon: LuPackage, color: 'blue' },
        { key: 'total_amount', title: 'Сумма', value: `${fmtMoney(metrics.total_amount)} ${symbol}`, icon: LuBanknote, color: 'green' },
        { key: 'avg_check', title: 'Средний чек', value: `${fmtMoney(metrics.avg_check)} ${symbol}`, icon: LuTrendingUp, color: 'purple' },
        { key: 'items_total_qty', title: 'Штук', value: fmtInt(metrics.items_total_qty), icon: LuLayers, color: 'orange' },
        { key: 'contractors_count', title: 'Контрагентов', value: fmtInt(metrics.contractors_count), icon: LuUsers, color: 'teal' },
    ];

    return (
        <SimpleGrid columns={{ base: 2, md: 3, lg: 5 }} gap={4}>
            {cards.map((c) => (
                <Box
                    key={c.key}
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
                            {deltas && <DeltaBadge delta={deltas[c.key]} />}
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
