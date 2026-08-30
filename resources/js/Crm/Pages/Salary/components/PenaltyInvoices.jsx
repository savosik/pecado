import { Badge, Box, HStack, Table, Text, VStack } from '@chakra-ui/react';
import { fmtDay, fmtRub0, plural } from './format';

const daysUntil = (iso) => {
    if (!iso) return null;
    const due = new Date(`${iso}T00:00:00`);
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    return Math.round((due - today) / 86400000);
};

/**
 * Накладные, за которые уже начислен штраф, и те, что под риском.
 *
 * Под риском — неоплаченные накладные со сроком в этом месяце: если платёж
 * придёт позже срока на три и более рабочих дня, штраф ляжет в этот же расчёт.
 * Список — материал для звонка клиенту сегодня, а не для разбора в конце месяца.
 */
export default function PenaltyInvoices({ calculation }) {
    const factor = (calculation.breakdown?.components ?? [])
        .find((c) => c.key === 'kpi_bonus')?.children?.find((c) => c.key === 'discipline_penalty');
    const penalized = factor?.evidence ?? [];
    const onTime = Number(factor?.meta?.on_time_count ?? 0);
    const atRisk = calculation.inputs?.at_risk_invoices ?? [];

    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
            <HStack justify="space-between" mb={3} flexWrap="wrap" gap={2}>
                <Text fontSize="xs" color="fg.muted" fontWeight="500">Финансовая дисциплина</Text>
                {onTime > 0 && (
                    <Text fontSize="xs" color="green.fg">
                        {onTime} {plural(onTime, 'накладная закрыта', 'накладные закрыты', 'накладных закрыто')} в срок
                    </Text>
                )}
            </HStack>

            {penalized.length === 0 ? (
                <Text fontSize="sm" color="fg.muted">Оплат с задержкой в этом месяце нет — штрафа нет.</Text>
            ) : (
                <Table.Root size="sm" variant="line">
                    <Table.Header>
                        <Table.Row>
                            <Table.ColumnHeader>Накладная</Table.ColumnHeader>
                            <Table.ColumnHeader>Партнёр</Table.ColumnHeader>
                            <Table.ColumnHeader textAlign="right">Сумма</Table.ColumnHeader>
                            <Table.ColumnHeader>Срок → оплата</Table.ColumnHeader>
                            <Table.ColumnHeader textAlign="right">Задержка</Table.ColumnHeader>
                            <Table.ColumnHeader textAlign="right">Штраф</Table.ColumnHeader>
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {penalized.map((r) => (
                            <Table.Row key={r.shipment_id}>
                                <Table.Cell whiteSpace="nowrap">
                                    {r.erp_number ?? '—'}
                                    {r.source === 'manual' && <Badge ml={1} size="xs" variant="subtle" colorPalette="gray">вручную</Badge>}
                                </Table.Cell>
                                <Table.Cell>{r.partner_name}</Table.Cell>
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
