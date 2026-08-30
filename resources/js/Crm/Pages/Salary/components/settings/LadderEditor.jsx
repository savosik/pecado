import { Box, HStack, Input, Table, Text } from '@chakra-ui/react';
import { LuPlus, LuTrash2 } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { RowActionButton } from '@/shared/Panel/RowActions';

const numberOr = (value, fallback = null) => {
    if (value === '' || value === null || value === undefined) return fallback;
    const n = Number(String(value).replace(',', '.'));

    return Number.isFinite(n) ? n : fallback;
};

/**
 * Лестница множителя по активным клиентам: порог доли (в процентах) → множитель.
 *
 * Первая ступень всегда с нуля — это проверяет сервер, здесь только ввод.
 * Значения хранятся как доли (0,8), а показываются процентами (80 %): так
 * записано в Excel РОПа, и так их привык читать отдел.
 */
export function LadderEditor({ ladder, onChange }) {
    const rows = ladder ?? [];

    const update = (index, patch) => onChange(rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));
    const remove = (index) => onChange(rows.filter((_, i) => i !== index));
    const add = () => {
        const last = rows[rows.length - 1];
        onChange([...rows, { from_share: last ? Math.min(2, last.from_share + 0.1) : 0, multiplier: last ? last.multiplier : 1 }]);
    };

    return (
        <Box>
            <Table.Root size="sm" variant="outline">
                <Table.Header>
                    <Table.Row>
                        <Table.ColumnHeader>Активных клиентов от, %</Table.ColumnHeader>
                        <Table.ColumnHeader>Множитель</Table.ColumnHeader>
                        <Table.ColumnHeader w="48px" />
                    </Table.Row>
                </Table.Header>
                <Table.Body>
                    {rows.map((row, index) => (
                        <Table.Row key={index}>
                            <Table.Cell>
                                <Input
                                    size="sm"
                                    type="number"
                                    min="0"
                                    max="1000"
                                    step="5"
                                    value={Math.round((row.from_share ?? 0) * 100)}
                                    onChange={(e) => update(index, { from_share: (numberOr(e.target.value, 0) ?? 0) / 100 })}
                                />
                            </Table.Cell>
                            <Table.Cell>
                                <Input
                                    size="sm"
                                    type="number"
                                    min="0"
                                    max="10"
                                    step="0.05"
                                    value={row.multiplier ?? ''}
                                    onChange={(e) => update(index, { multiplier: numberOr(e.target.value, 0) ?? 0 })}
                                />
                            </Table.Cell>
                            <Table.Cell>
                                <RowActionButton icon={LuTrash2} label="Убрать ступень" colorPalette="red" onClick={() => remove(index)} disabled={rows.length <= 1 ? 'Нужна хотя бы одна ступень' : false} />
                            </Table.Cell>
                        </Table.Row>
                    ))}
                </Table.Body>
            </Table.Root>
            <HStack mt={2} justify="space-between">
                <Text fontSize="xs" color="fg.muted">Берётся последняя ступень, чей порог не выше доли активных.</Text>
                <Button size="xs" variant="ghost" onClick={add}><LuPlus /> Ступень</Button>
            </HStack>
        </Box>
    );
}

/**
 * Ступени штрафа за дисциплину: задержка в рабочих днях → коэффициент от суммы накладной.
 * Пустое «до» — «и дальше», допустимо только у последней ступени.
 */
export function TiersEditor({ tiers, onChange }) {
    const rows = tiers ?? [];

    const update = (index, patch) => onChange(rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));
    const remove = (index) => onChange(rows.filter((_, i) => i !== index));
    const add = () => {
        const last = rows[rows.length - 1];
        const from = last ? (last.to_days ?? last.from_days) + 1 : 3;
        onChange([...rows.map((r, i) => (i === rows.length - 1 && r.to_days === null ? { ...r, to_days: from - 1 } : r)), { from_days: from, to_days: null, coefficient: last ? last.coefficient : 1.5 }]);
    };

    return (
        <Box>
            <Table.Root size="sm" variant="outline">
                <Table.Header>
                    <Table.Row>
                        <Table.ColumnHeader>Задержка от, раб. дн.</Table.ColumnHeader>
                        <Table.ColumnHeader>до (пусто — и дальше)</Table.ColumnHeader>
                        <Table.ColumnHeader>Коэффициент</Table.ColumnHeader>
                        <Table.ColumnHeader w="48px" />
                    </Table.Row>
                </Table.Header>
                <Table.Body>
                    {rows.map((row, index) => (
                        <Table.Row key={index}>
                            <Table.Cell>
                                <Input size="sm" type="number" min="1" step="1" value={row.from_days ?? ''} onChange={(e) => update(index, { from_days: numberOr(e.target.value, 1) ?? 1 })} />
                            </Table.Cell>
                            <Table.Cell>
                                <Input size="sm" type="number" min="1" step="1" placeholder="∞" value={row.to_days ?? ''} onChange={(e) => update(index, { to_days: numberOr(e.target.value, null) })} />
                            </Table.Cell>
                            <Table.Cell>
                                <Input size="sm" type="number" min="0" step="0.1" value={row.coefficient ?? ''} onChange={(e) => update(index, { coefficient: numberOr(e.target.value, 0) ?? 0 })} />
                            </Table.Cell>
                            <Table.Cell>
                                <RowActionButton icon={LuTrash2} label="Убрать ступень" colorPalette="red" onClick={() => remove(index)} />
                            </Table.Cell>
                        </Table.Row>
                    ))}
                </Table.Body>
            </Table.Root>
            <HStack mt={2} justify="space-between">
                <Text fontSize="xs" color="fg.muted">Штраф = коэффициент × сумма накладной; до первой ступени штрафа нет.</Text>
                <Button size="xs" variant="ghost" onClick={add}><LuPlus /> Ступень</Button>
            </HStack>
        </Box>
    );
}
