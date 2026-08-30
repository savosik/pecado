import { useCallback, useEffect, useState } from 'react';
import axios from 'axios';
import { Badge, Box, HStack, Input, Spinner, Table, Text, VStack } from '@chakra-ui/react';
import { LuUndo2 } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { SegmentedControl } from '@/components/ui/segmented-control';
import {
    DrawerBackdrop, DrawerBody, DrawerCloseTrigger, DrawerContent, DrawerFooter, DrawerHeader, DrawerRoot, DrawerTitle,
} from '@/components/ui/drawer';
import RowActions from '@/shared/Panel/RowActions';
import { toastError, toastSuccess } from '@/utils/toast';
import { fmtDay, fmtRub0 } from '../format';

const selectStyle = {
    padding: '0.4rem 0.6rem',
    borderRadius: '0.375rem',
    border: '1px solid var(--chakra-colors-border)',
    background: 'var(--chakra-colors-bg-panel)',
    color: 'var(--chakra-colors-fg)',
    minWidth: '160px',
};

const MODES = [
    { value: 'review', label: 'Спорные' },
    { value: 'manual', label: 'Размечены вручную' },
    { value: 'penalized', label: 'Со штрафом' },
];

/**
 * Спорные накладные: 1С считает их оплаченными, а какого числа пришли деньги — неизвестно.
 *
 * Это не очередь дел, а страховка. Дата платежа восстанавливается сопоставлением
 * с движениями регистра, и почти всегда это удаётся; остаётся хвост из зачётов
 * и платежей без ссылки на реализацию. Штраф по ним не начисляется — расчёт
 * трактует незнание в пользу менеджера. Разметка нужна только если руководитель
 * хочет учесть конкретную задержку; не размечать — рабочий вариант по умолчанию.
 *
 * В список попадают только накладные с прошедшим сроком: пока срок не наступил,
 * опоздать было невозможно, и разбирать нечего.
 */
export default function InvoicesPanel({ month, managers }) {
    const [mode, setMode] = useState('review');
    const [managerId, setManagerId] = useState('');
    const [filterMonth, setFilterMonth] = useState(mode === 'review' ? '' : month);
    const [data, setData] = useState({ rows: [], total: 0, amount: 0, truncated: false });
    const [loading, setLoading] = useState(false);
    const [editing, setEditing] = useState(null);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const params = { mode };
            if (managerId) params.manager = managerId;
            if (filterMonth) params.month = filterMonth;
            const res = await axios.get('/crm/salary/invoices', { params });
            setData(res.data);
        } catch (e) {
            toastError(e.response?.data?.message ?? 'Не удалось загрузить накладные');
        } finally {
            setLoading(false);
        }
    }, [mode, managerId, filterMonth]);

    useEffect(() => { load(); }, [load]);

    const replace = (invoice) => setData((prev) => ({
        ...prev,
        rows: mode === 'review' && !invoice.needs_review
            ? prev.rows.filter((r) => r.id !== invoice.id)
            : prev.rows.map((r) => (r.id === invoice.id ? invoice : r)),
        total: mode === 'review' && !invoice.needs_review ? prev.total - 1 : prev.total,
    }));

    const unmark = async (row) => {
        try {
            const res = await axios.delete(`/crm/salary/invoices/${row.id}/mark`);
            toastSuccess('Ручная дата снята');
            if (mode === 'manual') {
                setData((prev) => ({ ...prev, rows: prev.rows.filter((r) => r.id !== row.id), total: prev.total - 1 }));
            } else {
                replace(res.data.invoice);
            }
        } catch (e) {
            toastError(e.response?.data?.message ?? 'Не удалось снять дату');
        }
    };

    return (
        <VStack align="stretch" gap={3}>
            <HStack gap={3} flexWrap="wrap">
                <SegmentedControl size="sm" value={mode} onValueChange={(e) => { setMode(e.value); setFilterMonth(e.value === 'review' ? '' : month); }} items={MODES} />
                <select aria-label="Менеджер" style={selectStyle} value={managerId} onChange={(e) => setManagerId(e.target.value)}>
                    <option value="">Все менеджеры</option>
                    {managers.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                </select>
                <Input type="month" size="sm" maxW="170px" value={filterMonth} onChange={(e) => setFilterMonth(e.target.value)} aria-label="Месяц" />
                {loading && <Spinner size="sm" />}
                <Text fontSize="xs" color="fg.muted" ml="auto">
                    {data.total} накл. на {fmtRub0(data.amount ?? 0)}
                    {data.truncated ? ` · показаны ${data.rows.length} крупнейших` : ''}
                </Text>
            </HStack>

            {mode === 'review' && (
                <Box borderWidth="1px" borderColor="border" borderRadius="lg" p={3} bg="bg.subtle">
                    <Text fontSize="sm">
                        1С считает эти накладные оплаченными, но какого числа пришли деньги — не сообщает
                        (зачёт, платёж без ссылки на реализацию). <Text as="span" fontWeight="600">Штраф по ним не начисляется</Text> — расчёт
                        толкует незнание в пользу менеджера.
                    </Text>
                    <Text fontSize="xs" color="fg.muted" mt={1}>
                        Разбирать не обязательно: заходите сюда, только если хотите учесть конкретную задержку.
                        Накладные со сроком в будущем сюда не попадают — опоздать по ним было нельзя.
                    </Text>
                </Box>
            )}
            {mode !== 'review' && (
                <Text fontSize="xs" color="fg.muted">
                    {mode === 'manual'
                        ? 'Дата закрытия проставлена руководителем; ночная пересборка её не трогает.'
                        : 'Накладные, закрытые с задержкой выше льготной — по ним начислен штраф.'}
                </Text>
            )}

            <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflowX="auto">
                {data.rows.length === 0 ? (
                    <Text p={4} fontSize="sm" color="fg.muted">
                        {loading ? 'Загрузка…' : mode === 'review' ? 'Спорных накладных нет — по всем оплатам дата известна.' : 'Пусто.'}
                    </Text>
                ) : (
                    <Table.Root size="sm" variant="line">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeader>Накладная</Table.ColumnHeader>
                                <Table.ColumnHeader>Партнёр</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">Сумма</Table.ColumnHeader>
                                <Table.ColumnHeader>Отгружена</Table.ColumnHeader>
                                <Table.ColumnHeader>Срок</Table.ColumnHeader>
                                <Table.ColumnHeader>Закрыта</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">Задержка</Table.ColumnHeader>
                                <Table.ColumnHeader w="72px" />
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {data.rows.map((row) => (
                                <Table.Row key={row.id}>
                                    <Table.Cell whiteSpace="nowrap">{row.erp_number ?? '—'}</Table.Cell>
                                    <Table.Cell>{row.partner_name}</Table.Cell>
                                    <Table.Cell textAlign="right" fontVariantNumeric="tabular-nums" whiteSpace="nowrap">{fmtRub0(row.amount)}</Table.Cell>
                                    <Table.Cell whiteSpace="nowrap">{fmtDay(row.shipped_on)}</Table.Cell>
                                    <Table.Cell whiteSpace="nowrap">{fmtDay(row.due_on)}</Table.Cell>
                                    <Table.Cell whiteSpace="nowrap">
                                        {row.settled_on ? fmtDay(row.settled_on) : <Text as="span" color="fg.muted">—</Text>}
                                        {row.settled_source === 'manual' && (
                                            <Badge ml={1} size="xs" variant="subtle" colorPalette="blue" title={row.manual_comment ?? ''}>вручную</Badge>
                                        )}
                                    </Table.Cell>
                                    <Table.Cell textAlign="right" whiteSpace="nowrap" color={row.delay_working_days > 2 ? 'red.fg' : undefined}>
                                        {row.delay_working_days === null || row.delay_working_days === undefined ? '—' : `${row.delay_working_days} раб. дн.`}
                                    </Table.Cell>
                                    <Table.Cell>
                                        <RowActions
                                            edit={{ onClick: () => setEditing(row), label: 'Проставить дату оплаты' }}
                                            extra={row.manual_settled_on ? [{ key: 'unmark', icon: LuUndo2, label: 'Снять ручную дату', onClick: () => unmark(row) }] : []}
                                        />
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </Table.Root>
                )}
            </Box>

            <MarkDrawer invoice={editing} onClose={() => setEditing(null)} onSaved={(inv) => { replace(inv); setEditing(null); }} />
        </VStack>
    );
}

function MarkDrawer({ invoice, onClose, onSaved }) {
    const [date, setDate] = useState('');
    const [comment, setComment] = useState('');
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        setDate(invoice?.manual_settled_on ?? invoice?.matched_settled_on ?? '');
        setComment(invoice?.manual_comment ?? '');
        setErrors({});
    }, [invoice]);

    if (!invoice) return null;

    const save = async () => {
        setSaving(true);
        setErrors({});
        try {
            const res = await axios.patch(`/crm/salary/invoices/${invoice.id}`, { settled_on: date, comment });
            toastSuccess('Дата оплаты проставлена');
            onSaved(res.data.invoice);
        } catch (e) {
            setErrors(e.response?.data?.errors ?? {});
            toastError(e.response?.data?.message ?? 'Не удалось сохранить');
        } finally {
            setSaving(false);
        }
    };

    const field = (key) => (Array.isArray(errors[key]) ? errors[key][0] : errors[key]);

    return (
        <DrawerRoot open onOpenChange={(e) => { if (!e.open) onClose(); }} size="md">
            <DrawerBackdrop />
            <DrawerContent>
                <DrawerHeader>
                    <DrawerTitle>{invoice.erp_number ?? 'Накладная'} · {fmtRub0(invoice.amount)}</DrawerTitle>
                    <Text fontSize="sm" color="fg.muted">{invoice.partner_name} · отгружена {fmtDay(invoice.shipped_on)} · срок {fmtDay(invoice.due_on)}</Text>
                    <DrawerCloseTrigger />
                </DrawerHeader>
                <DrawerBody>
                    <VStack align="stretch" gap={4}>
                        {invoice.payments?.length > 0 && (
                            <Box fontSize="xs" color="fg.muted">
                                <Text fontWeight="600" mb={1}>Сопоставленные платежи</Text>
                                {invoice.payments.map((p) => (
                                    <Text key={p.entry_uuid}>{fmtDay(p.date)} — {fmtRub0(p.amount)} ({p.kind === 'order' ? 'аванс по заказу' : 'по накладной'}{p.document_number ? `, ${p.document_number}` : ''})</Text>
                                ))}
                            </Box>
                        )}
                        <Field label="Дата фактической оплаты" errorText={field('settled_on')} invalid={Boolean(field('settled_on'))}>
                            <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
                        </Field>
                        <Field label="Основание" helperText="Откуда известна дата: письмо клиента, зачёт, платёжка без номера…" errorText={field('comment')} invalid={Boolean(field('comment'))}>
                            <Input value={comment} onChange={(e) => setComment(e.target.value)} maxLength={255} />
                        </Field>
                    </VStack>
                </DrawerBody>
                <DrawerFooter>
                    <Button variant="ghost" onClick={onClose} disabled={saving}>Отмена</Button>
                    <Button colorPalette="blue" onClick={save} loading={saving} disabled={!date || !comment}>Сохранить</Button>
                </DrawerFooter>
            </DrawerContent>
        </DrawerRoot>
    );
}
