import { useState } from 'react';
import axios from 'axios';
import { Box, HStack, Input, SimpleGrid, Table, Text } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { ConfirmDialog } from '@/Admin/Components/ConfirmDialog';
import RowActions from '@/shared/Panel/RowActions';
import { useConfirmDelete } from '@/shared/Panel/useConfirmDelete';
import { toastError, toastSuccess } from '@/utils/toast';
import { fmtDateTime, fmtRub } from '../format';

const selectStyle = {
    padding: '0.45rem 0.6rem',
    borderRadius: '0.375rem',
    border: '1px solid var(--chakra-colors-border)',
    background: 'var(--chakra-colors-bg-panel)',
    color: 'var(--chakra-colors-fg)',
    width: '100%',
};

const EMPTY = { manager_id: '', component: 'extra_income', label: '', qty: '1', price: '', comment: '' };

/**
 * Ручные строки месяца: позиции доп. дохода (ТГ-каналы, рассылки) и корректировки РОПа.
 *
 * Правки нет намеренно: строка несёт автора и основание, ошибочную удаляют и
 * заводят заново — след остаётся. Замороженный месяц сервер не даст тронуть.
 */
export default function AdjustmentsPanel({ month, managers, adjustments, onChanged }) {
    const [form, setForm] = useState(EMPTY);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const del = useConfirmDelete({
        title: 'Удалить строку?',
        description: (row) => (row ? `«${row.label}» на ${fmtRub(row.amount)} будет удалена, расчёт пересчитается.` : ''),
        onConfirm: async (row) => {
            try {
                const res = await axios.delete(`/crm/salary/adjustments/${row.id}`);
                toastSuccess('Строка удалена');
                onChanged?.(res.data.adjustments);
            } catch (e) {
                toastError(e.response?.data?.message ?? 'Не удалось удалить');
            }
        },
    });

    const submit = async () => {
        setSaving(true);
        setErrors({});
        try {
            const res = await axios.post('/crm/salary/adjustments', { ...form, month });
            toastSuccess('Строка добавлена');
            onChanged?.(res.data.adjustments);
            setForm({ ...EMPTY, manager_id: form.manager_id, component: form.component });
        } catch (e) {
            const data = e.response?.data;
            setErrors(data?.errors ?? {});
            toastError(data?.message ?? 'Не удалось сохранить');
        } finally {
            setSaving(false);
        }
    };

    const field = (key) => (Array.isArray(errors[key]) ? errors[key][0] : errors[key]);
    const amount = (Number(form.qty || 1) * Number(form.price || 0)) || 0;

    return (
        <SimpleGrid columns={{ base: 1, xl: '1fr 2fr' }} gap={5} alignItems="start">
            <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
                <Text fontWeight="600" mb={3}>Новая строка</Text>
                <SimpleGrid columns={1} gap={3}>
                    <Field label="Менеджер" errorText={field('manager_id')} invalid={Boolean(field('manager_id'))}>
                        <select style={selectStyle} value={form.manager_id} onChange={(e) => setForm({ ...form, manager_id: e.target.value })}>
                            <option value="">Выберите…</option>
                            {managers.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                        </select>
                    </Field>
                    <Field label="Тип" errorText={field('component')} invalid={Boolean(field('component'))}>
                        <select style={selectStyle} value={form.component} onChange={(e) => setForm({ ...form, component: e.target.value })}>
                            <option value="extra_income">Доп. доход (ТГ-канал, рассылка…)</option>
                            <option value="manual_correction">Корректировка (доплата или удержание)</option>
                        </select>
                    </Field>
                    <Field label="Название" errorText={field('label')} invalid={Boolean(field('label'))}>
                        <Input value={form.label} onChange={(e) => setForm({ ...form, label: e.target.value })} placeholder="ТГ-канал «Pecado Опт»" maxLength={255} />
                    </Field>
                    <HStack gap={3} align="start">
                        <Field label="Количество" errorText={field('qty')} invalid={Boolean(field('qty'))} maxW="120px">
                            <Input type="number" min="0.01" step="1" value={form.qty} onChange={(e) => setForm({ ...form, qty: e.target.value })} />
                        </Field>
                        <Field label="Цена, ₽" errorText={field('price')} invalid={Boolean(field('price'))} helperText={form.component === 'manual_correction' ? 'Удержание — со знаком минус' : undefined}>
                            <Input type="number" step="100" value={form.price} onChange={(e) => setForm({ ...form, price: e.target.value })} />
                        </Field>
                    </HStack>
                    <Field label="Основание" optionalText="необязательно" errorText={field('comment')} invalid={Boolean(field('comment'))}>
                        <Input value={form.comment} onChange={(e) => setForm({ ...form, comment: e.target.value })} maxLength={255} />
                    </Field>
                    <HStack justify="space-between">
                        <Text fontSize="sm" color="fg.muted">Сумма: <Text as="span" fontWeight="600" color="fg">{fmtRub(amount)}</Text></Text>
                        <Button colorPalette="blue" onClick={submit} loading={saving} disabled={!form.manager_id || !form.label || form.price === ''}>Добавить</Button>
                    </HStack>
                </SimpleGrid>
            </Box>

            <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflowX="auto">
                {adjustments.length === 0 ? (
                    <Text p={4} fontSize="sm" color="fg.muted">Ручных строк за этот месяц нет.</Text>
                ) : (
                    <Table.Root size="sm" variant="line">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeader>Менеджер</Table.ColumnHeader>
                                <Table.ColumnHeader>Тип</Table.ColumnHeader>
                                <Table.ColumnHeader>Позиция</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">Сумма</Table.ColumnHeader>
                                <Table.ColumnHeader>Кто и когда</Table.ColumnHeader>
                                <Table.ColumnHeader w="56px" />
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {adjustments.map((row) => (
                                <Table.Row key={row.id}>
                                    <Table.Cell whiteSpace="nowrap">{row.manager_name}</Table.Cell>
                                    <Table.Cell whiteSpace="nowrap" color="fg.muted">{row.component_label}</Table.Cell>
                                    <Table.Cell>
                                        <Text>{row.label}{row.qty !== 1 ? ` × ${row.qty}` : ''}</Text>
                                        {row.comment && <Text fontSize="xs" color="fg.muted">{row.comment}</Text>}
                                    </Table.Cell>
                                    <Table.Cell textAlign="right" fontVariantNumeric="tabular-nums" color={row.amount < 0 ? 'red.fg' : undefined} whiteSpace="nowrap">
                                        {fmtRub(row.amount)}
                                    </Table.Cell>
                                    <Table.Cell whiteSpace="nowrap" fontSize="xs" color="fg.muted">{row.author ?? '—'} · {fmtDateTime(row.created_at)}</Table.Cell>
                                    <Table.Cell><RowActions delete={{ onClick: () => del.request(row) }} /></Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </Table.Root>
                )}
            </Box>
            <ConfirmDialog {...del.dialogProps} />
        </SimpleGrid>
    );
}
