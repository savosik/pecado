import { Badge, Box, Collapsible, HStack, Table, Text, VStack } from '@chakra-ui/react';
import { LuChevronDown } from 'react-icons/lu';
import MetricHint from '@/Crm/Components/MetricHint';
import { fmtDay, fmtRub0, plural } from './format';

const daysUntil = (iso) => {
    if (!iso) return null;
    const due = new Date(`${iso}T00:00:00`);
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    return Math.round((due - today) / 86400000);
};

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
    const atRisk = calculation.inputs?.at_risk_invoices ?? [];
    const groups = groupByPartner(penalized);
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

            {atRisk.length > 0 && (
                <VStack align="stretch" gap={2} mt={4}>
                    <HStack justify="space-between" flexWrap="wrap" gap={2}>
                        <Text fontSize="xs" color="fg.muted" fontWeight="500">
                            Под риском — не оплачены, срок в этом месяце
                        </Text>
                        <Text fontSize="xs" color="fg.muted">
                            {calculation.inputs?.at_risk_count ?? atRisk.length} на {fmtRub0(calculation.inputs?.at_risk_amount ?? 0)}
                            {(calculation.inputs?.at_risk_count ?? 0) > atRisk.length ? ` · показаны ${atRisk.length} крупнейших` : ''}
                        </Text>
                    </HStack>
                    {atRisk.map((r) => {
                        const left = daysUntil(r.due_on);
                        const overdue = left !== null && left < 0;

                        return (
                            <HStack key={r.shipment_id} justify="space-between" fontSize="sm" gap={3} flexWrap="wrap">
                                <HStack gap={2} minW={0}>
                                    <Text whiteSpace="nowrap">{r.erp_number ?? '—'}</Text>
                                    <Text color="fg.muted" lineClamp={1}>{r.partner_name}</Text>
                                </HStack>
                                <HStack gap={3} whiteSpace="nowrap">
                                    <Text fontVariantNumeric="tabular-nums">{fmtRub0(r.amount)}</Text>
                                    <Badge colorPalette={overdue ? 'red' : left <= 3 ? 'orange' : 'gray'} variant="subtle" size="sm">
                                        {left === null
                                            ? 'срок не задан'
                                            : overdue
                                                ? `просрочено ${-left} дн.`
                                                : left === 0 ? 'срок сегодня' : `срок через ${left} дн.`}
                                    </Badge>
                                </HStack>
                            </HStack>
                        );
                    })}
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
