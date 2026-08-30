import { Badge, Box, Collapsible, HStack, Table, Text, VStack } from '@chakra-ui/react';
import { LuChevronDown } from 'react-icons/lu';
import MetricHint from '@/Crm/Components/MetricHint';
import { fmtDay, fmtRub0, plural } from './format';

/**
 * Накладные, по которым выручка уменьшена за опоздание оплаты, и те, что под риском.
 *
 * Колонка называется «вычет», а не «штраф»: рубли не удерживают из зарплаты —
 * они уходят из реализаций, зачтённых в план. Из-за коэффициента ступени вычет
 * бывает втрое больше накладной, и слово «штраф» рядом с такой цифрой читалось
 * как удержание из кармана.
 *
 * Накладные сгруппированы по клиентам и отсортированы по сумме вычета: сверху
 * тот, кто испортил картину сильнее всех. Простыня на 140 строк не отвечала на
 * главный вопрос — с кем разбираться первым; у одного партнёра здесь 63 накладные,
 * и в общем списке он был неотличим от того, кто опоздал однажды.
 *
 * Под риском — неоплаченные накладные со сроком в этом месяце: если платёж
 * придёт позже срока на три и более рабочих дня, вычет ляжет в этот же расчёт.
 * Список — материал для звонка клиенту сегодня, а не для разбора в конце месяца.
 */
export default function PenaltyInvoices({ calculation }) {
    const factor = (calculation.breakdown?.components ?? [])
        .find((c) => c.key === 'kpi_bonus')?.children?.find((c) => c.key === 'discipline_penalty');
    const penalized = factor?.evidence ?? [];
    const onTime = Number(factor?.meta?.on_time_count ?? 0);
    const groups = groupByPartner(penalized);
    const buckets = calculation.forecast?.risk_buckets ?? [];
    const riskCount = buckets.reduce((acc, b) => acc + b.count, 0);
    const riskAmount = buckets.reduce((acc, b) => acc + Number(b.amount ?? 0), 0);
    const totalPenalty = groups.reduce((acc, g) => acc + g.penalty, 0);

    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
            <HStack justify="space-between" mb={3} flexWrap="wrap" gap={2}>
                <HStack gap={2} flexWrap="wrap">
                    <Text fontSize="xs" color="fg.muted" fontWeight="500">Финансовая дисциплина</Text>
                    {penalized.length > 0 && (
                        <Text fontSize="xs" color="fg.muted">
                            · {penalized.length} {plural(penalized.length, 'накладная закрыта', 'накладные закрыты', 'накладных закрыто')} с опозданием
                            у {groups.length} {plural(groups.length, 'клиента', 'клиентов', 'клиентов')} · вычет {fmtRub0(totalPenalty)}
                        </Text>
                    )}
                </HStack>
                {onTime > 0 && (
                    <Text fontSize="xs" color="green.fg">
                        {onTime} {plural(onTime, 'накладная закрыта', 'накладные закрыты', 'накладных закрыто')} в срок
                    </Text>
                )}
            </HStack>

            {penalized.length === 0 ? (
                <Text fontSize="sm" color="fg.muted">Оплат с задержкой в этом месяце нет — из выручки ничего не вычтено.</Text>
            ) : (
                <VStack align="stretch" gap={2}>
                    {groups.map((group, index) => (
                        <PartnerGroup key={group.key} group={group} rank={index + 1} total={totalPenalty} />
                    ))}
                </VStack>
            )}

            {buckets.length > 0 && (
                <VStack align="stretch" gap={3} mt={5}>
                    <HStack justify="space-between" flexWrap="wrap" gap={2}>
                        <HStack gap={2}>
                            <Text fontSize="xs" color="fg.muted" fontWeight="500">Ещё не оплачены</Text>
                            <MetricHint text="Пока деньги не пришли, из выручки не вычтено ничего: вычет появляется в момент оплаты, а его размер задаёт задержка. Поэтому накладные сгруппированы не по сумме, а по тому, что с ними можно успеть сделать." />
                        </HStack>
                        <Text fontSize="xs" color="fg.muted" fontVariantNumeric="tabular-nums">
                            {riskCount} на {fmtRub0(riskAmount)}
                        </Text>
                    </HStack>

                    {buckets.map((bucket) => (
                        <RiskBucket key={bucket.key} bucket={bucket} />
                    ))}
                </VStack>
            )}
        </Box>
    );
}

/**
 * Кто сильнее испортил картину — по сумме вычета, а не по числу накладных.
 *
 * Вычет = сумма × коэффициент ступени, поэтому одна крупная накладная с
 * недельной задержкой перевешивает десяток мелких: сортировка по деньгам
 * ставит наверх того, с кем разговор действительно стоит денег.
 */
function groupByPartner(rows) {
    const map = new Map();

    rows.forEach((row) => {
        const key = row.partner_id ?? row.partner_name;

        if (!map.has(key)) {
            map.set(key, { key, name: row.partner_name, rows: [], penalty: 0, amount: 0, maxDelay: 0 });
        }

        const group = map.get(key);
        group.rows.push(row);
        group.penalty += Number(row.penalty ?? 0);
        group.amount += Number(row.amount ?? 0);
        group.maxDelay = Math.max(group.maxDelay, Number(row.delay_working_days ?? 0));
    });

    const groups = [...map.values()];
    groups.forEach((g) => g.rows.sort((a, b) => Number(b.penalty ?? 0) - Number(a.penalty ?? 0)));
    groups.sort((a, b) => b.penalty - a.penalty);

    return groups;
}

function PartnerGroup({ group, rank, total }) {
    const share = total > 0 ? group.penalty / total : 0;

    return (
        <Collapsible.Root borderWidth="1px" borderColor="border" borderRadius="lg" overflow="hidden">
            <Collapsible.Trigger asChild>
                <Box
                    as="button"
                    type="button"
                    w="100%"
                    px={3}
                    py={2.5}
                    textAlign="left"
                    cursor="pointer"
                    _hover={{ bg: 'bg.subtle' }}
                    transition="background 0.15s"
                >
                    <HStack gap={3} align="center">
                        <Text fontSize="xs" color="fg.subtle" fontVariantNumeric="tabular-nums" minW="16px">{rank}</Text>

                        <Box flex="1" minW={0}>
                            <HStack gap={2} flexWrap="wrap">
                                <Text fontSize="sm" fontWeight="600" lineClamp={1}>{group.name}</Text>
                                <Text fontSize="xs" color="fg.muted" whiteSpace="nowrap">
                                    {group.rows.length} {plural(group.rows.length, 'накладная', 'накладные', 'накладных')} на {fmtRub0(group.amount)}
                                    {group.maxDelay > 0 ? ` · до ${group.maxDelay} раб. дн.` : ''}
                                </Text>
                            </HStack>
                            {/* Доля вычета — сразу видно, кто съел большую часть выручки. */}
                            <Box mt={1.5} h="4px" bg="bg.muted" borderRadius="full" overflow="hidden">
                                <Box h="100%" w={`${Math.max(2, share * 100)}%`} bg="red.solid" borderRadius="full" />
                            </Box>
                        </Box>

                        <VStack gap={0} align="end" flexShrink={0}>
                            <Text fontSize="sm" fontWeight="700" color="red.fg" fontVariantNumeric="tabular-nums" whiteSpace="nowrap">
                                −{fmtRub0(group.penalty)}
                            </Text>
                            <Text fontSize="10px" color="fg.subtle" fontVariantNumeric="tabular-nums">
                                {Math.round(share * 100)} % вычета
                            </Text>
                        </VStack>

                        <Box color="fg.muted" flexShrink={0} transition="transform 0.2s" css={{ '[data-state=open] &': { transform: 'rotate(180deg)' } }}>
                            <LuChevronDown size={16} />
                        </Box>
                    </HStack>
                </Box>
            </Collapsible.Trigger>

            <Collapsible.Content>
                <Box borderTopWidth="1px" borderColor="border" maxH="320px" overflowY="auto">
                    <Table.Root size="sm" variant="line">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeader>Накладная</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">Сумма</Table.ColumnHeader>
                                <Table.ColumnHeader>Срок → оплата</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">Задержка</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">
                                    <HStack gap={1} justify="flex-end">
                                        <Text as="span">Вычет из выручки</Text>
                                        <MetricHint text="Из зарплаты эти рубли не удерживают. Сумма накладной, умноженная на коэффициент ступени, вычитается из реализаций, которые засчитываются в план: премия падает через процент выполнения, а не прямым удержанием. Поэтому вычет и бывает больше самой накладной." />
                                    </HStack>
                                </Table.ColumnHeader>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {group.rows.map((r) => (
                                <Table.Row key={r.shipment_id}>
                                    <Table.Cell whiteSpace="nowrap">
                                        {r.erp_number ?? '—'}
                                        {r.source === 'manual' && <Badge ml={1} size="xs" variant="subtle" colorPalette="gray">вручную</Badge>}
                                    </Table.Cell>
                                    <Table.Cell textAlign="right" fontVariantNumeric="tabular-nums">{fmtRub0(r.amount)}</Table.Cell>
                                    <Table.Cell whiteSpace="nowrap" color="fg.muted">{fmtDay(r.due_on)} → {fmtDay(r.settled_on)}</Table.Cell>
                                    <Table.Cell textAlign="right" whiteSpace="nowrap">
                                        {r.delay_working_days} раб. дн. <Text as="span" color="fg.muted">× {r.coefficient}</Text>
                                    </Table.Cell>
                                    <Table.Cell textAlign="right" color="red.fg" fontWeight="600" fontVariantNumeric="tabular-nums">
                                        −{fmtRub0(r.penalty)}
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </Table.Root>
                </Box>
            </Collapsible.Content>
        </Collapsible.Root>
    );
}

const TONE = {
    safe: { palette: 'green', color: 'var(--chakra-colors-green-solid)' },
    rising: { palette: 'orange', color: 'var(--chakra-colors-orange-solid)' },
    worst: { palette: 'red', color: 'var(--chakra-colors-red-solid)' },
    stale: { palette: 'gray', color: 'var(--chakra-colors-border-emphasized)' },
};

/**
 * Группа неоплаченных накладных: что здесь на кону и сколько осталось времени.
 *
 * Заголовок называет исход, а не состояние: «успеть без вычета» вместо «срок
 * сегодня». Цена вопроса — разница между «оплатят сейчас» и «дотянут до худшей
 * ступени»: у растущей группы она вдвое, и это единственное место, где промедление
 * менеджера ещё что-то решает.
 */
function RiskBucket({ bucket }) {
    const tone = TONE[bucket.key] ?? TONE.stale;
    const growth = Number(bucket.penalty_worst ?? 0) - Number(bucket.penalty_now ?? 0);
    const left = bucket.deadline ? daysLeft(bucket.deadline) : null;

    return (
        <Collapsible.Root borderWidth="1px" borderColor="border" borderRadius="lg" overflow="hidden">
            <Collapsible.Trigger asChild>
                <Box as="button" type="button" w="100%" px={3} py={2.5} textAlign="left" cursor="pointer" _hover={{ bg: 'bg.subtle' }} transition="background 0.15s">
                    <HStack gap={3} align="center">
                        <Box w="4px" alignSelf="stretch" minH="34px" borderRadius="full" bg={tone.color} flexShrink={0} />

                        <Box flex="1" minW={0}>
                            <HStack gap={2} flexWrap="wrap">
                                <Text fontSize="sm" fontWeight="600">{bucket.title}</Text>
                                <Badge size="xs" variant="subtle" colorPalette={tone.palette} fontVariantNumeric="tabular-nums">
                                    {bucket.count} {plural(bucket.count, 'накладная', 'накладные', 'накладных')} на {fmtRub0(bucket.amount)}
                                </Badge>
                                {left !== null && (
                                    <Badge size="xs" variant="subtle" colorPalette={left <= 0 ? 'red' : left <= 2 ? 'orange' : 'gray'}>
                                        {left <= 0 ? 'крайний срок сегодня' : `осталось ${left} ${plural(left, 'день', 'дня', 'дней')} — до ${fmtDay(bucket.deadline)}`}
                                    </Badge>
                                )}
                            </HStack>
                            <Text fontSize="xs" color="fg.subtle" mt={0.5}>{bucket.note}</Text>
                        </Box>

                        <VStack gap={0} align="end" flexShrink={0}>
                            <Text fontSize="sm" fontWeight="700" fontVariantNumeric="tabular-nums" whiteSpace="nowrap" color={bucket.penalty_now > 0 ? 'red.fg' : 'green.fg'}>
                                {bucket.penalty_now > 0 ? `−${fmtRub0(bucket.penalty_now)}` : 'вычета нет'}
                            </Text>
                            {growth > 1 && (
                                <Text fontSize="10px" color="fg.subtle" fontVariantNumeric="tabular-nums" whiteSpace="nowrap">
                                    промедлят — {fmtRub0(bucket.penalty_worst)}
                                </Text>
                            )}
                        </VStack>

                        <Box color="fg.muted" flexShrink={0} transition="transform 0.2s" css={{ '[data-state=open] &': { transform: 'rotate(180deg)' } }}>
                            <LuChevronDown size={16} />
                        </Box>
                    </HStack>
                </Box>
            </Collapsible.Trigger>

            <Collapsible.Content>
                <Box borderTopWidth="1px" borderColor="border" maxH="300px" overflowY="auto">
                    <Table.Root size="sm" variant="line">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeader>Накладная</Table.ColumnHeader>
                                <Table.ColumnHeader>Партнёр</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">Сумма</Table.ColumnHeader>
                                <Table.ColumnHeader>Срок оплаты</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">
                                    {bucket.key === 'safe' ? 'Будет вычет, если опоздают' : 'Вычет при оплате сейчас'}
                                </Table.ColumnHeader>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {bucket.rows.map((r) => (
                                <Table.Row key={r.shipment_id}>
                                    <Table.Cell whiteSpace="nowrap">{r.erp_number ?? '—'}</Table.Cell>
                                    <Table.Cell><Text lineClamp={1}>{r.partner_name}</Text></Table.Cell>
                                    <Table.Cell textAlign="right" fontVariantNumeric="tabular-nums">{fmtRub0(r.amount)}</Table.Cell>
                                    <Table.Cell whiteSpace="nowrap" color="fg.muted">
                                        {fmtDay(r.due_on)}
                                        {r.overdue_days > 0 ? ` · ${r.overdue_days} ${plural(r.overdue_days, 'день', 'дня', 'дней')} назад` : ''}
                                    </Table.Cell>
                                    <Table.Cell textAlign="right" color="red.fg" fontWeight="600" fontVariantNumeric="tabular-nums" whiteSpace="nowrap">
                                        −{fmtRub0(bucket.key === 'safe' ? r.penalty_worst : r.penalty_now)}
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                            {bucket.count > bucket.rows.length && (
                                <Table.Row>
                                    <Table.Cell colSpan={5} color="fg.muted" fontSize="xs">
                                        Показаны {bucket.rows.length} крупнейших из {bucket.count}.
                                    </Table.Cell>
                                </Table.Row>
                            )}
                        </Table.Body>
                    </Table.Root>
                </Box>
            </Collapsible.Content>
        </Collapsible.Root>
    );
}

function daysLeft(iso) {
    const target = new Date(`${iso}T00:00:00`);
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    return Math.round((target - today) / 86400000);
}
