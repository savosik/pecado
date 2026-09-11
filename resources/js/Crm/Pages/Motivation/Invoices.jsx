import { useEffect, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { Badge, Box, HStack, SimpleGrid, Table, Text, VStack } from '@chakra-ui/react';
import { LuCalendarCheck, LuUndo2 } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Pagination } from '@/Admin/Components/Pagination';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import MetricHint from '@/Crm/Components/MetricHint';
import { toastError, toastSuccess } from '@/utils/toast';
import { fmtDay, fmtRub0 } from '../Salary/components/format';

const inputStyle = {
    padding: '0.45rem 0.6rem',
    borderRadius: '0.5rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '150px',
    background: 'var(--chakra-colors-bg-panel)',
    color: 'var(--chakra-colors-fg)',
};

/**
 * Очередь разметки накладных: по каким не восстановлена дата оплаты и во что
 * каждая обходится работнику в этом месяце. Разбирать — от дорогих к дешёвым.
 */
export default function MotivationInvoices(props) {
    const [data, setData] = useState(props);
    const [busy, setBusy] = useState(false);
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState({ settled_on: '', comment: '' });

    useEffect(() => { setData(props); }, [props]);

    const navigate = (changes) => {
        const params = { ...data.query, ...changes };
        Object.keys(params).forEach((k) => { if (params[k] === '' || params[k] === null || params[k] === undefined) delete params[k]; });
        router.get('/crm/motivation/invoices', params, { preserveState: true, preserveScroll: true, replace: true });
    };

    const call = async (method, url, payload) => {
        setBusy(true);
        try {
            const res = await axios({ method, url, data: payload, params: data.query });
            setData(res.data);
            if (res.data.message) toastSuccess(res.data.message);
            return true;
        } catch (e) {
            toastError(e.response?.data?.message ?? Object.values(e.response?.data?.errors ?? {}).flat().join(' ') ?? 'Не удалось выполнить');
            return false;
        } finally {
            setBusy(false);
        }
    };

    const rows = data.rows?.data ?? [];

    return (
        <CrmLayout breadcrumbs={[{ label: 'Мотивация' }, { label: 'Очередь разметки' }]}>
            <Head title="Очередь разметки накладных — CRM" />
            <PageHeader title="Очередь разметки накладных" description="По каким накладным не восстановлена дата оплаты и сколько каждая добавляет к вычету работника." />

            <VStack align="stretch" gap={4}>
                <Alert status="info" title="Незнание толкуется в пользу работника">
                    1С считает эти накладные оплаченными, но какого числа пришли деньги — не сообщает (зачёт, платёж без ссылки на реализацию). Пока дата не проставлена, расчёт начисляет вычет по состоянию «не погашено» — колонка «В расчёте» показывает, во что это обходится.
                </Alert>

                <SimpleGrid columns={{ base: 1, md: 3 }} gap={3}>
                    <Stat label="Накладных в очереди" value={String(data.summary.total)} />
                    <Stat label="На сумму" value={fmtRub0(data.summary.amount)} />
                    <Stat label="Добавляют к К1 за месяц" value={fmtRub0(data.summary.k1_cost)} />
                </SimpleGrid>

                <HStack gap={2} flexWrap="wrap">
                    <input type="month" aria-label="Месяц" style={inputStyle} value={data.query.month} onChange={(e) => navigate({ month: e.target.value, page: undefined })} />
                    <select aria-label="Работник" style={inputStyle} value={data.query.manager ?? ''} onChange={(e) => navigate({ manager: e.target.value || undefined, page: undefined })}>
                        <option value="">Все работники</option>
                        {data.managers.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                    </select>
                </HStack>

                {editing && data.can_edit && (
                    <Box bg="bg.panel" borderWidth="2px" borderColor="blue.solid" borderRadius="xl" p={4}>
                        <Text fontWeight="700" mb={1}>{editing.erp_number} · {fmtRub0(editing.amount)} · {editing.partner_name}</Text>
                        {editing.payments?.length > 0 && (
                            <Box fontSize="xs" color="fg.muted" mb={2}>
                                <Text fontWeight="600">Сопоставленные платежи</Text>
                                {editing.payments.map((p, i) => <Text key={p.entry_uuid ?? i}>{fmtDay(p.date)} — {fmtRub0(p.amount)}{p.document_number ? ` (${p.document_number})` : ''}</Text>)}
                            </Box>
                        )}
                        <HStack gap={3} flexWrap="wrap" align="flex-end">
                            <label style={{ fontSize: '0.75rem' }}>Дата фактической оплаты<br /><input type="date" style={inputStyle} value={form.settled_on} onChange={(e) => setForm({ ...form, settled_on: e.target.value })} /></label>
                            <label style={{ fontSize: '0.75rem', flex: 1 }}>Основание<br /><input style={{ ...inputStyle, width: '100%' }} placeholder="Письмо клиента, зачёт, платёжка без номера…" maxLength={255} value={form.comment} onChange={(e) => setForm({ ...form, comment: e.target.value })} /></label>
                            <Button size="sm" variant="ghost" onClick={() => setEditing(null)}>Отмена</Button>
                            <Button size="sm" loading={busy} disabled={!form.settled_on || !form.comment} onClick={async () => { if (await call('patch', `/crm/motivation/invoices/${editing.id}`, form)) setEditing(null); }}><LuCalendarCheck /> Проставить дату</Button>
                        </HStack>
                    </Box>
                )}

                <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflowX="auto">
                    {rows.length === 0 ? <Text p={4} fontSize="sm" color="fg.muted">Спорных накладных нет — по всем оплатам дата известна.</Text> : (
                        <>
                            <Table.Root size="sm">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>Накладная</Table.ColumnHeader>
                                        <Table.ColumnHeader>Партнёр</Table.ColumnHeader>
                                        <Table.ColumnHeader>Работник</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">Сумма</Table.ColumnHeader>
                                        <Table.ColumnHeader>Срок</Table.ColumnHeader>
                                        <Table.ColumnHeader>Закрыта</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right"><HStack justify="flex-end" gap={1}>В расчёте<MetricHint text="Сколько накладная добавляет к К1 работника за выбранный месяц по состоянию «не погашено». Считается тем же интегратором, что расчёт." /></HStack></Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">Действия</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {rows.map((r) => (
                                        <Table.Row key={r.id}>
                                            <Table.Cell><Text fontSize="sm" whiteSpace="nowrap">{r.erp_number ?? '—'}</Text><Text fontSize="xs" color="fg.subtle">отгружена {fmtDay(r.shipped_on)}</Text></Table.Cell>
                                            <Table.Cell><Text fontSize="sm">{r.partner_name}</Text></Table.Cell>
                                            <Table.Cell><Text fontSize="sm">{r.manager_name}</Text></Table.Cell>
                                            <Table.Cell textAlign="right"><Text fontSize="sm">{fmtRub0(r.amount)}</Text></Table.Cell>
                                            <Table.Cell><Text fontSize="sm">{fmtDay(r.due_on)}</Text></Table.Cell>
                                            <Table.Cell>
                                                <Text fontSize="sm">{r.settled_on ? fmtDay(r.settled_on) : '—'}</Text>
                                                {r.settled_source === 'manual' && <Badge size="xs" variant="subtle" colorPalette="blue" title={r.manual_comment ?? ''}>вручную · {r.manual_by}</Badge>}
                                            </Table.Cell>
                                            <Table.Cell textAlign="right"><Text fontSize="sm" fontWeight="700" color={r.k1_cost > 0 ? 'red.fg' : 'fg.subtle'}>{fmtRub0(r.k1_cost)}</Text></Table.Cell>
                                            <Table.Cell textAlign="right">
                                                {data.can_edit && (
                                                    <HStack justify="flex-end" gap={1}>
                                                        <Button size="xs" variant="outline" onClick={() => { setEditing(r); setForm({ settled_on: r.manual_settled_on ?? r.matched_settled_on ?? '', comment: r.manual_comment ?? '' }); }}><LuCalendarCheck /> Дата оплаты</Button>
                                                        {r.manual_settled_on && <Button size="xs" variant="ghost" aria-label="Снять ручную дату" title="Снять ручную дату" loading={busy} onClick={() => call('delete', `/crm/motivation/invoices/${r.id}/mark`)}><LuUndo2 /></Button>}
                                                    </HStack>
                                                )}
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                            <Pagination pagination={data.rows} onPageChange={(page) => navigate({ page })} />
                        </>
                    )}
                </Box>
            </VStack>
        </CrmLayout>
    );
}

function Stat({ label, value }) {
    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
            <Text fontSize="xs" color="fg.muted">{label}</Text>
            <Text fontSize="2xl" fontWeight="800" fontVariantNumeric="tabular-nums">{value}</Text>
        </Box>
    );
}
