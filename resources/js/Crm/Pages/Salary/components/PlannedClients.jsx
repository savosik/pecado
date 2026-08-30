import { Badge, Box, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { fmtCompact, fmtRub0, plural } from './format';

/**
 * Плановые клиенты месяца — пять блоков: охват и четыре группы по выполнению.
 *
 * Один длинный список отвечал только на «кто ещё не купил». Но в множитель KPI
 * входит охват (сколько плановых отгрузилось), а работа с недобравшим клиентом
 * и с не заказавшим — разная: первому продают остаток, второму звонят. Поэтому
 * группы разделены, и у каждой видно, сколько денег в ней стоит на кону.
 *
 * Коридор «план закрыт» — ±5 %: ровно в план не попадает почти никто, и без
 * допуска группа «выполнили» всегда была бы пустой.
 */
const TOLERANCE = 0.05;

const GROUPS = [
    {
        key: 'over',
        title: 'Перевыполнили план',
        note: 'больше 105 % плана',
        tone: 'green',
        color: 'var(--chakra-colors-green-solid)',
    },
    {
        key: 'done',
        title: 'Выполнили план',
        note: '95–105 % плана',
        tone: 'blue',
        color: 'var(--chakra-colors-blue-solid)',
    },
    {
        key: 'under',
        title: 'Недовыполнили план',
        note: 'купили, но меньше плана',
        tone: 'orange',
        color: 'var(--chakra-colors-orange-solid)',
    },
    {
        key: 'silent',
        title: 'Не заказали',
        note: 'плановые без единой отгрузки',
        tone: 'gray',
        color: 'var(--chakra-colors-border-emphasized)',
    },
];

function split(rows) {
    const buckets = { over: [], done: [], under: [], silent: [] };

    rows.forEach((row) => {
        const plan = Number(row.plan ?? 0);
        const fact = Number(row.fact ?? 0);

        if (!(fact > 0)) {
            buckets.silent.push(row);
        } else if (plan <= 0) {
            // Плана нет, а продажа есть — считаем сверх плана, а не недобором.
            buckets.over.push(row);
        } else {
            const share = fact / plan;
            buckets[share >= 1 + TOLERANCE ? 'over' : share >= 1 - TOLERANCE ? 'done' : 'under'].push(row);
        }
    });

    Object.values(buckets).forEach((list) => list.sort((a, b) => (b.fact ?? 0) - (a.fact ?? 0) || (b.plan ?? 0) - (a.plan ?? 0)));

    return buckets;
}

const sum = (rows, field) => rows.reduce((acc, row) => acc + Number(row[field] ?? 0), 0);

export default function PlannedClients({ calculation }) {
    const rows = calculation.inputs?.planned_clients ?? [];

    if (rows.length === 0) {
        return null;
    }

    const buckets = split(rows);
    const groups = GROUPS.map((g) => {
        const list = buckets[g.key];

        return { ...g, list, count: list.length, plan: sum(list, 'plan'), fact: sum(list, 'fact') };
    });

    const total = rows.length;
    const active = total - buckets.silent.length;
    const planTotal = sum(rows, 'plan');
    const factTotal = sum(rows, 'fact');

    return (
        <VStack align="stretch" gap={5}>
            <Coverage groups={groups} total={total} active={active} planTotal={planTotal} factTotal={factTotal} />

            <SimpleGrid columns={{ base: 1, xl: 2 }} gap={5} alignItems="start">
                {groups.map((group) => <GroupPanel key={group.key} group={group} />)}
            </SimpleGrid>
        </VStack>
    );
}

/** Блок 1 — охват: из скольких плановых клиентов кто-то купил и как разложились остальные. */
function Coverage({ groups, total, active, planTotal, factTotal }) {
    const share = total > 0 ? active / total : 0;
    const planShare = planTotal > 0 ? factTotal / planTotal : null;

    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={{ base: 4, md: 5 }}>
            <HStack justify="space-between" mb={3} flexWrap="wrap" gap={2}>
                <Text fontSize="xs" color="fg.muted" fontWeight="500">Охват плановых клиентов</Text>
                <Text fontSize="xs" color="fg.muted">
                    в множитель премии идёт доля тех, кто купил
                </Text>
            </HStack>

            <HStack align="baseline" gap={3} flexWrap="wrap" mb={1}>
                <Text fontSize={{ base: '2xl', md: '3xl' }} fontWeight="800" lineHeight="1.1" fontVariantNumeric="tabular-nums">
                    {active} из {total}
                </Text>
                <Badge size="lg" variant="subtle" colorPalette={share >= 0.9 ? 'green' : share >= 0.8 ? 'orange' : 'red'}>
                    {Math.round(share * 100)} % охвата
                </Badge>
                <Text fontSize="sm" color="fg.muted" fontVariantNumeric="tabular-nums">
                    отгружено {fmtRub0(factTotal)} из {fmtRub0(planTotal)}
                    {planShare !== null ? ` · ${Math.round(planShare * 100)} % плана по клиентам` : ''}
                </Text>
            </HStack>

            {/* Состав: ширина сегмента = доля клиентов, зазор 2px, чтобы группы не слипались. */}
            <HStack h="16px" gap="2px" mt={4} mb={2}>
                {groups.filter((g) => g.count > 0).map((g) => (
                    <Box
                        key={g.key}
                        flex={g.count}
                        h="100%"
                        bg={g.color}
                        borderRadius="sm"
                        title={`${g.title}: ${g.count}`}
                    />
                ))}
            </HStack>

            <HStack gap={4} flexWrap="wrap">
                {groups.map((g) => (
                    <HStack key={g.key} gap={1.5}>
                        <Box w="10px" h="10px" borderRadius="sm" bg={g.color} flexShrink={0} />
                        <Text fontSize="xs" color="fg.muted">{g.title}</Text>
                        <Text fontSize="xs" fontWeight="700" fontVariantNumeric="tabular-nums">{g.count}</Text>
                    </HStack>
                ))}
            </HStack>
        </Box>
    );
}

/** Блоки 2–5 — группа: сколько клиентов, сколько отгружено и сколько было надо. */
function GroupPanel({ group }) {
    const { title, note, tone, color, list, count, plan, fact } = group;
    const share = plan > 0 ? fact / plan : null;
    const gap = fact - plan;

    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
            <HStack justify="space-between" align="start" gap={3} mb={3} flexWrap="wrap">
                <HStack gap={2} minW={0}>
                    <Box w="10px" h="10px" borderRadius="sm" bg={color} flexShrink={0} />
                    <Box minW={0}>
                        <Text fontSize="sm" fontWeight="700" lineClamp={1}>{title}</Text>
                        <Text fontSize="xs" color="fg.subtle">{note}</Text>
                    </Box>
                </HStack>
                <Badge size="lg" variant="subtle" colorPalette={tone} fontVariantNumeric="tabular-nums">
                    {count} {plural(count, 'клиент', 'клиента', 'клиентов')}
                </Badge>
            </HStack>

            <HStack gap={4} flexWrap="wrap" mb={3}>
                <Box>
                    <Text fontSize="xs" color="fg.muted">Отгружено</Text>
                    <Text fontSize="lg" fontWeight="700" fontVariantNumeric="tabular-nums">{fmtRub0(fact)}</Text>
                </Box>
                <Box>
                    <Text fontSize="xs" color="fg.muted">Надо было</Text>
                    <Text fontSize="lg" fontWeight="700" color="fg.muted" fontVariantNumeric="tabular-nums">{fmtRub0(plan)}</Text>
                </Box>
                {share !== null && (
                    <Box>
                        <Text fontSize="xs" color="fg.muted">Выполнение</Text>
                        <HStack gap={2} align="baseline">
                            <Text fontSize="lg" fontWeight="700" color={`${tone}.fg`} fontVariantNumeric="tabular-nums">
                                {Math.round(share * 100)} %
                            </Text>
                            {Math.abs(gap) >= 1 && (
                                <Text fontSize="xs" color={gap > 0 ? 'green.fg' : 'fg.muted'} fontVariantNumeric="tabular-nums">
                                    {gap > 0 ? '+' : '−'}{fmtCompact(Math.abs(gap))}
                                </Text>
                            )}
                        </HStack>
                    </Box>
                )}
            </HStack>

            {count === 0 ? (
                <Text fontSize="sm" color="fg.muted">Никого — и это нормально.</Text>
            ) : (
                <VStack align="stretch" gap={0} maxH="240px" overflowY="auto">
                    {list.map((row) => {
                        const rowPlan = Number(row.plan ?? 0);
                        const rowFact = Number(row.fact ?? 0);
                        const rowShare = rowPlan > 0 ? rowFact / rowPlan : null;

                        return (
                            <HStack key={row.id} justify="space-between" gap={3} py={1.5} fontSize="sm" borderBottomWidth="1px" borderColor="border.subtle">
                                <Text lineClamp={1} minW={0}>{row.name}</Text>
                                <HStack gap={2} whiteSpace="nowrap" flexShrink={0}>
                                    <Text fontVariantNumeric="tabular-nums" color={rowFact > 0 ? undefined : 'fg.muted'}>
                                        {rowFact > 0 ? fmtCompact(rowFact) : '—'}
                                    </Text>
                                    <Text fontSize="xs" color="fg.muted" fontVariantNumeric="tabular-nums">из {fmtCompact(rowPlan)}</Text>
                                    {rowShare !== null && (
                                        <Text fontSize="xs" color={`${tone}.fg`} fontVariantNumeric="tabular-nums" minW="42px" textAlign="right">
                                            {Math.round(rowShare * 100)} %
                                        </Text>
                                    )}
                                </HStack>
                            </HStack>
                        );
                    })}
                </VStack>
            )}
        </Box>
    );
}
