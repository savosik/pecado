import { useMemo, useState } from 'react';
import { Badge, Box, HStack, SimpleGrid, Text } from '@chakra-ui/react';
import { LuFileWarning, LuShieldCheck, LuTrendingUp, LuUsers, LuZap } from 'react-icons/lu';
import MetricHint from '@/Crm/Components/MetricHint';
import { fmtRub0, fmtSigned } from './format';

const KIND = {
    clients: { icon: LuUsers, palette: 'blue' },
    invoice: { icon: LuFileWarning, palette: 'orange' },
    revenue: { icon: LuTrendingUp, palette: 'green' },
};

const AFFECTS = {
    revenue: 'план',
    clients: 'охват',
    penalty: 'дисциплина',
};

/**
 * Фильтры: цена вопроса по каждому направлению.
 *
 * Суммы намеренно пересекаются — совет с двойным эффектом входит и в «план»,
 * и в «охват», и в свою группу. Это не разбиение денег на доли, а ответ на
 * вопрос «сколько всего стоит заняться этим направлением».
 */
const FILTERS = [
    { key: 'all', label: 'Все', match: () => true, palette: 'gray' },
    { key: 'revenue', label: 'План', match: (r) => (r.affects ?? []).includes('revenue'), palette: 'green' },
    { key: 'clients', label: 'Охват', match: (r) => (r.affects ?? []).includes('clients'), palette: 'blue' },
    { key: 'penalty', label: 'Дисциплина', match: (r) => (r.affects ?? []).includes('penalty'), palette: 'orange' },
    { key: 'synergy', label: 'Двойной эффект', match: (r) => (r.affects ?? []).length > 1, palette: 'purple' },
];

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
    const [filter, setFilter] = useState('all');

    const chips = useMemo(() => FILTERS.map((f) => {
        const list = rows.filter(f.match);

        return { ...f, count: list.length, sum: list.reduce((acc, r) => acc + Number(r.gain ?? 0), 0) };
    }).filter((f) => f.count > 0), [rows]);

    if (rows.length === 0) {
        return null;
    }

    const active = chips.some((c) => c.key === filter) ? filter : 'all';
    const shown = rows.filter(FILTERS.find((f) => f.key === active).match);
    const feasible = shown.filter((r) => r.feasible !== false);
    const later = shown.filter((r) => r.feasible === false);

    return (
        <Box>
            <HStack gap={2} mb={2} flexWrap="wrap">
                <Text fontSize="xs" color="fg.muted" fontWeight="500">
                    Что можно поднять{feasible.length > 0 ? ' — сначала то, что реально успеть' : ''}
                </Text>
                <MetricHint text="Суммы на кнопках пересекаются: совет с двойным эффектом входит сразу в два направления. Это цена вопроса по направлению, а не доли одной суммы — сложить их и получить прибавку к премии нельзя, показатели перемножаются." />
            </HStack>

            <HStack gap={2} mb={3} flexWrap="wrap">
                {chips.map((chip) => {
                    const on = chip.key === active;

                    return (
                        <Badge
                            key={chip.key}
                            as="button"
                            type="button"
                            size="lg"
                            variant={on ? 'solid' : 'subtle'}
                            colorPalette={chip.palette}
                            cursor="pointer"
                            onClick={() => setFilter(on && chip.key !== 'all' ? 'all' : chip.key)}
                            opacity={on || active === 'all' ? 1 : 0.6}
                        >
                            {chip.label}
                            <Text as="span" fontVariantNumeric="tabular-nums" fontWeight="700" ml={1}>
                                {fmtRub0(chip.sum)}
                            </Text>
                            <Text as="span" fontSize="10px" opacity={0.75} ml={1}>· {chip.count}</Text>
                        </Badge>
                    );
                })}
            </HStack>

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
