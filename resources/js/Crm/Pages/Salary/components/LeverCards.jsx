import { Badge, Box, HStack, SimpleGrid, Text } from '@chakra-ui/react';
import { LuFileWarning, LuShieldCheck, LuTrendingUp, LuUsers, LuZap } from 'react-icons/lu';
import { fmtSigned } from './format';

const KIND = {
    clients: { icon: LuUsers, palette: 'blue' },
    invoice: { icon: LuFileWarning, palette: 'orange' },
    revenue: { icon: LuTrendingUp, palette: 'green' },
};

const AFFECTS = {
    revenue: 'выручка',
    clients: 'охват',
    penalty: 'дисциплина',
};

/**
 * Рычаги: что сделать и сколько это даст — сверху то, что успеть реально.
 *
 * Порядок задаёт сервер: сначала выполнимое за оставшиеся дни, внутри — по
 * деньгам. Совет с прибавкой в 31 000 ₽ уходит вниз, если для него нужно за день
 * отгрузить два миллиона: рейтинг по одной лишь сумме звал делать невозможное.
 *
 * Значок молнии — ход, двигающий два показателя сразу: отгрузка молчащему
 * плановому клиенту растит выручку и тянет охват к следующей ступени, а ступень
 * умножает премию целиком.
 */
export default function LeverCards({ advice }) {
    const rows = advice ?? [];

    if (rows.length === 0) {
        return null;
    }

    const feasible = rows.filter((r) => r.feasible !== false);
    const later = rows.filter((r) => r.feasible === false);

    return (
        <Box>
            <Text fontSize="xs" color="fg.muted" fontWeight="500" mb={2}>
                Что можно поднять{feasible.length > 0 ? ' — сначала то, что реально успеть' : ''}
            </Text>

            {feasible.length > 0 && <Cards rows={feasible} />}

            {later.length > 0 && (
                <>
                    <Text fontSize="xs" color="fg.subtle" fontWeight="500" mt={feasible.length > 0 ? 5 : 0} mb={2}>
                        До конца месяца вряд ли — но цена вопроса такая
                    </Text>
                    <Cards rows={later} dimmed />
                </>
            )}
        </Box>
    );
}

function Cards({ rows, dimmed = false }) {
    return (
        <SimpleGrid columns={{ base: 1, md: 2, xl: 3 }} gap={3}>
            {rows.map((row) => {
                const meta = KIND[row.kind] ?? KIND.revenue;
                const Icon = meta.icon;
                const affects = row.affects ?? [];
                const synergy = affects.length > 1;

                return (
                    <Box
                        key={row.key}
                        bg="bg.panel"
                        borderWidth="1px"
                        borderColor="border"
                        borderRadius="xl"
                        p={4}
                        borderTopWidth="3px"
                        borderTopColor={`${meta.palette}.solid`}
                        opacity={dimmed ? 0.65 : 1}
                    >
                        <HStack justify="space-between" align="start" gap={3}>
                            <HStack gap={2} align="start" minW={0}>
                                <Box color={`${meta.palette}.fg`} mt="2px" flexShrink={0}><Icon size={16} /></Box>
                                <Text fontWeight="600" fontSize="sm">{row.title}</Text>
                            </HStack>
                            <Badge colorPalette={meta.palette} variant="subtle" whiteSpace="nowrap" fontVariantNumeric="tabular-nums">
                                {row.protective ? `−${fmtSigned(row.gain).replace('+', '')}` : fmtSigned(row.gain)}
                            </Badge>
                        </HStack>

                        <Text fontSize="xs" color="fg.muted" mt={2}>{row.detail}</Text>

                        <HStack gap={1.5} mt={2.5} flexWrap="wrap">
                            {row.protective && (
                                <Badge size="xs" variant="subtle" colorPalette="gray">
                                    <LuShieldCheck size={11} /> не потерять
                                </Badge>
                            )}
                            {synergy && (
                                <Badge size="xs" variant="subtle" colorPalette="purple">
                                    <LuZap size={11} /> двойной эффект
                                </Badge>
                            )}
                            {affects.map((a) => (
                                <Badge key={a} size="xs" variant="outline" colorPalette="gray">{AFFECTS[a] ?? a}</Badge>
                            ))}
                        </HStack>
                    </Box>
                );
            })}
        </SimpleGrid>
    );
}
