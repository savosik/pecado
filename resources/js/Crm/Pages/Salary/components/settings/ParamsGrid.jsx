import { Badge, Box, HStack, Table, Text } from '@chakra-ui/react';
import RowActions from '@/shared/Panel/RowActions';
import { fmtFactor, fmtPercent, fmtRub0 } from '../format';

const SOURCE = {
    permanent: { label: 'постоянно', palette: 'blue' },
    month: { label: 'на месяц', palette: 'orange' },
};

const Cell = ({ value, source }) => (
    <HStack gap={1} justify="flex-end" whiteSpace="nowrap">
        <Text fontVariantNumeric="tabular-nums">{value}</Text>
        {SOURCE[source] && <Badge size="xs" variant="subtle" colorPalette={SOURCE[source].palette}>{SOURCE[source].label}</Badge>}
    </HStack>
);

const ladderSummary = (ladder) => (ladder ?? [])
    .map((s) => `${fmtPercent(s.from_share, 0).replace(' %', '%')}→${fmtFactor(s.multiplier)}`)
    .join(' · ');

const tiersSummary = (tiers) => (tiers ?? [])
    .map((t) => `${t.from_days}${t.to_days === null || t.to_days === undefined ? '+' : `–${t.to_days}`} дн ×${fmtFactor(t.coefficient)}`)
    .join(' · ');

const STATUS_PALETTE = { draft: 'blue', approved: 'green', paid: 'gray' };

/**
 * Сетка «менеджер × параметры» за месяц. Бейдж у значения — откуда оно:
 * без бейджа — по схеме, «постоянно» — личное, «на месяц» — отклонение месяца.
 */
export default function ParamsGrid({ managers, onEdit }) {
    return (
        <Box overflowX="auto" bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl">
            <Table.Root size="sm" variant="line">
                <Table.Header>
                    <Table.Row>
                        <Table.ColumnHeader>Менеджер</Table.ColumnHeader>
                        <Table.ColumnHeader textAlign="right">Оклад</Table.ColumnHeader>
                        <Table.ColumnHeader textAlign="right">База KPI</Table.ColumnHeader>
                        <Table.ColumnHeader textAlign="right">Потолок</Table.ColumnHeader>
                        <Table.ColumnHeader>Лестница активных</Table.ColumnHeader>
                        <Table.ColumnHeader>Ступени штрафа</Table.ColumnHeader>
                        <Table.ColumnHeader textAlign="right">Расчёт за месяц</Table.ColumnHeader>
                        <Table.ColumnHeader w="64px" />
                    </Table.Row>
                </Table.Header>
                <Table.Body>
                    {managers.map((m) => {
                        const kpi = m.params?.kpi_bonus ?? {};
                        const src = m.sources ?? {};

                        return (
                            <Table.Row key={m.id}>
                                <Table.Cell>
                                    <Text fontWeight="600">{m.name}</Text>
                                    {!m.has_account && <Text fontSize="xs" color="fg.muted">без учётной записи CRM</Text>}
                                </Table.Cell>
                                <Table.Cell textAlign="right"><Cell value={fmtRub0(m.params?.salary?.amount)} source={src.salary?.amount} /></Table.Cell>
                                <Table.Cell textAlign="right"><Cell value={fmtRub0(kpi.base)} source={src.kpi_bonus?.base} /></Table.Cell>
                                <Table.Cell textAlign="right"><Cell value={`×${fmtFactor(kpi.cap)}`} source={src.kpi_bonus?.cap} /></Table.Cell>
                                <Table.Cell>
                                    <HStack gap={1}>
                                        <Text fontSize="xs" color="fg.muted" whiteSpace="nowrap">{ladderSummary(kpi.active_clients?.ladder)}</Text>
                                        {SOURCE[src.kpi_bonus?.active_clients] && <Badge size="xs" variant="subtle" colorPalette={SOURCE[src.kpi_bonus.active_clients].palette}>{SOURCE[src.kpi_bonus.active_clients].label}</Badge>}
                                    </HStack>
                                </Table.Cell>
                                <Table.Cell>
                                    <HStack gap={1}>
                                        <Text fontSize="xs" color="fg.muted" whiteSpace="nowrap">{tiersSummary(kpi.discipline_penalty?.tiers)}</Text>
                                        {SOURCE[src.kpi_bonus?.discipline_penalty] && <Badge size="xs" variant="subtle" colorPalette={SOURCE[src.kpi_bonus.discipline_penalty].palette}>{SOURCE[src.kpi_bonus.discipline_penalty].label}</Badge>}
                                    </HStack>
                                </Table.Cell>
                                <Table.Cell textAlign="right">
                                    {m.calculation ? (
                                        <HStack gap={2} justify="flex-end" whiteSpace="nowrap">
                                            <Text fontVariantNumeric="tabular-nums">{fmtRub0(m.calculation.total)}</Text>
                                            <Badge size="xs" variant="subtle" colorPalette={STATUS_PALETTE[m.calculation.status] ?? 'gray'}>{m.calculation.status_label}</Badge>
                                        </HStack>
                                    ) : <Text fontSize="xs" color="fg.muted">ещё не считался</Text>}
                                </Table.Cell>
                                <Table.Cell>
                                    <RowActions
                                        edit={{ onClick: () => onEdit(m), label: 'Параметры', disabled: m.calculation?.is_frozen ? 'Месяц утверждён — сначала переоткройте расчёт' : false }}
                                    />
                                </Table.Cell>
                            </Table.Row>
                        );
                    })}
                </Table.Body>
            </Table.Root>
        </Box>
    );
}
