import { Link } from '@inertiajs/react';
import { Box, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { LuArrowRight, LuFileWarning, LuSparkles, LuTrendingUp } from 'react-icons/lu';
import { fmtSigned } from '../../Salary/components/format';

const KIND = {
    plan: { icon: LuTrendingUp, palette: 'green' },
    debt: { icon: LuFileWarning, palette: 'orange' },
    focus: { icon: LuSparkles, palette: 'purple' },
};

/**
 * Что поднимет доход: карточки с действием и ценой в рублях.
 *
 * Порядок задаёт сервер — сверху то, что достижимее и даёт больше. Цена
 * каждой карточки посчитана тем же калькулятором изоляцией, поэтому
 * прибавки складываются с итогом, а не друг с другом.
 */
export default function MotivationLevers({ levers }) {
    const rows = levers ?? [];

    if (rows.length === 0) {
        return null;
    }

    return (
        <Box>
            <Text fontWeight="700" mb={2}>Что поднимет доход</Text>
            <SimpleGrid columns={{ base: 1, md: rows.length >= 3 ? 3 : rows.length }} gap={3}>
                {rows.map((lever) => {
                    const kind = KIND[lever.key] ?? KIND.plan;
                    const Icon = kind.icon;

                    return (
                        <Box key={lever.key} bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
                            <VStack align="stretch" gap={2} h="100%">
                                <HStack gap={2} align="start">
                                    <Box p={2} borderRadius="lg" bg={`${kind.palette}.subtle`} color={`${kind.palette}.fg`} display="flex" flexShrink={0}>
                                        <Icon size={18} />
                                    </Box>
                                    <Text fontWeight="600" fontSize="sm" flex="1">{lever.title}</Text>
                                </HStack>
                                <Text fontSize="2xl" fontWeight="800" color={`${kind.palette}.fg`} fontVariantNumeric="tabular-nums">
                                    {fmtSigned(lever.value)}
                                </Text>
                                <Text fontSize="xs" color="fg.muted" flex="1">{lever.hint}</Text>
                                {lever.href && (
                                    <Link href={lever.href}>
                                        <HStack gap={1} fontSize="sm" color="fg.muted" _hover={{ color: 'fg' }}>
                                            <Text>Открыть</Text>
                                            <LuArrowRight size={14} />
                                        </HStack>
                                    </Link>
                                )}
                            </VStack>
                        </Box>
                    );
                })}
            </SimpleGrid>
        </Box>
    );
}
