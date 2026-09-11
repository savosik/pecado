import { useEffect, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { Badge, Box, HStack, SimpleGrid, Table, Text, Textarea, VStack } from '@chakra-ui/react';
import { LuCheck, LuCircleCheck, LuCircleX, LuLock, LuRefreshCw, LuRotateCcw, LuTrash2, LuWallet } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { toastError, toastSuccess } from '@/utils/toast';
import { fmtDateTime, fmtPercent, fmtRub0, fmtSigned } from '../Salary/components/format';

const selectStyle = {
    padding: '0.45rem 0.6rem',
    borderRadius: '0.5rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '180px',
    background: 'var(--chakra-colors-bg-panel)',
    color: 'var(--chakra-colors-fg)',
};

const inputStyle = { ...selectStyle, minWidth: '140px' };
const STATUS_PALETTE = { draft: 'blue', approved: 'green', paid: 'gray' };
const OBJECTION_PALETTE = { open: 'orange', accepted: 'green', rejected: 'gray' };

/**
 * Ведомость к утверждению: можно ли закрывать месяц.
 *
 * Автоматической заморозки нет (решение 08.09.2026): руководитель утверждает
 * руками, и до утверждения месяц остаётся черновиком, пересчитываемым
 * по мере поступления документов из 1С.
 */
export default function MotivationApproval(props) {
    const [data, setData] = useState(props);
    const [busy, setBusy] = useState(false);
    const [reopenFor, setReopenFor] = useState(null);
    const [reopenComment, setReopenComment] = useState('');
    const [correction, setCorrection] = useState({ manager_id: '', amount: '', reason: '' });
    const [responding, setResponding] = useState({});

    useEffect(() => setData(props), [props]);

    const monthKey = data.month.slice(0, 7);
    const changeMonth = (m) => router.get('/crm/motivation/approval', { month: m }, { preserveState: true, preserveScroll: true, replace: true });

    const call = async (method, url, payload = {}) => {
        setBusy(true);
        try {
            const res = method === 'delete'
                ? await axios.delete(url, { data: { month: monthKey, ...payload } })
                : await axios.post(url, { month: monthKey, ...payload });
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

    const allReady = data.readiness.every((r) => r.ok);

    return (
        <CrmLayout breadcrumbs={[{ label: 'Мотивация' }, { label: 'Ведомость к утверждению' }]}>
            <Head title="Ведомость к утверждению — CRM" />
            <PageHeader
                title="Ведомость к утверждению"
                description="Можно ли закрывать месяц. Автоматической заморозки нет: до утверждения расчёт остаётся черновиком и пересчитывается по новым документам."
                actions={(
                    <select aria-label="Месяц" style={selectStyle} value={monthKey} onChange={(e) => changeMonth(e.target.value)}>
                        {data.months.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
                    </select>
                )}
            />

            <VStack align="stretch" gap={4}>
                <Box bg="bg.panel" borderWidth="2px" borderColor={allReady ? 'green.solid' : 'border'} borderRadius="xl" p={4}>
                    <Text fontWeight="700" mb={2}>Готовность к закрытию</Text>
                    <SimpleGrid columns={{ base: 1, md: 2 }} gap={2}>
                        {data.readiness.map((r) => (
                            <HStack key={r.key} align="start" gap={2}>
                                <Box color={r.ok ? 'green.fg' : 'orange.fg'} mt={0.5}>{r.ok ? <LuCircleCheck size={16} /> : <LuCircleX size={16} />}</Box>
                                <Box>
                                    <Text fontSize="sm" fontWeight="600">{r.label}</Text>
                                    <Text fontSize="xs" color="fg.muted">
                                        {r.detail}{r.href && !r.ok ? <> · <Link href={r.href}><u>перейти</u></Link></> : null}
                                    </Text>
                                </Box>
                            </HStack>
                        ))}
                    </SimpleGrid>
                </Box>

                <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflowX="auto">
                    <Table.Root size="sm">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeader>Работник</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">Выполнение</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">Переменная</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">Корректировки</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">Гарантия</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">Итого</Table.ColumnHeader>
                                <Table.ColumnHeader>Статус</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">Действия</Table.ColumnHeader>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {data.rows.map((r) => {
                                const c = r.calculation;
                                return (
                                    <Table.Row key={r.manager.id}>
                                        <Table.Cell>
                                            <Text fontSize="sm" fontWeight="600">{r.manager.name}</Text>
                                            {r.needs_review > 0 && <Text fontSize="xs" color="orange.fg">не разобрано накладных: {r.needs_review}</Text>}
                                            {r.open_objections > 0 && <Text fontSize="xs" color="orange.fg">возражений без ответа: {r.open_objections}</Text>}
                                        </Table.Cell>
                                        <Table.Cell textAlign="right"><Text fontSize="sm">{r.percent === null ? '—' : fmtPercent(r.percent, 0)}</Text></Table.Cell>
                                        <Table.Cell textAlign="right"><Text fontSize="sm" fontVariantNumeric="tabular-nums">{fmtRub0(r.variable)}</Text></Table.Cell>
                                        <Table.Cell textAlign="right"><Text fontSize="sm" fontVariantNumeric="tabular-nums" color={r.correction !== 0 ? (r.correction > 0 ? 'green.fg' : 'red.fg') : 'fg.subtle'}>{r.correction !== 0 ? fmtSigned(r.correction) : '—'}</Text></Table.Cell>
                                        <Table.Cell textAlign="right"><Text fontSize="sm" fontVariantNumeric="tabular-nums" color={r.guarantee > 0 ? 'purple.fg' : 'fg.subtle'}>{r.guarantee > 0 ? fmtSigned(r.guarantee) : '—'}</Text></Table.Cell>
                                        <Table.Cell textAlign="right"><Text fontSize="sm" fontWeight="800" fontVariantNumeric="tabular-nums">{fmtRub0(r.total)}</Text></Table.Cell>
                                        <Table.Cell>
                                            <Badge colorPalette={STATUS_PALETTE[c.status] ?? 'blue'} variant="subtle" size="sm">{c.frozen && <LuLock size={10} />} {c.status_label}{c.version > 1 ? ` · v${c.version}` : ''}</Badge>
                                            {c.approved_at && <Text fontSize="xs" color="fg.subtle">{fmtDateTime(c.approved_at)}</Text>}
                                        </Table.Cell>
                                        <Table.Cell textAlign="right">
                                            {data.can_edit && (
                                                <HStack justify="flex-end" gap={1}>
                                                    {c.status === 'draft' && (
                                                        <>
                                                            <Button size="xs" variant="ghost" aria-label="Пересчитать" title="Пересчитать" loading={busy} onClick={() => call('post', `/crm/motivation/calculations/${c.id}/recalculate`)}><LuRefreshCw /></Button>
                                                            <Button size="xs" colorPalette="green" loading={busy} onClick={() => call('post', `/crm/motivation/calculations/${c.id}/approve`)}><LuCheck /> Утвердить</Button>
                                                        </>
                                                    )}
                                                    {c.status === 'approved' && (
                                                        <>
                                                            <Button size="xs" variant="outline" loading={busy} onClick={() => call('post', `/crm/motivation/calculations/${c.id}/paid`)}><LuWallet /> Выплачено</Button>
                                                            <Button size="xs" variant="ghost" aria-label="Переоткрыть" title="Переоткрыть новой версией" onClick={() => { setReopenFor(c.id); setReopenComment(''); }}><LuRotateCcw /></Button>
                                                        </>
                                                    )}
                                                    {c.status === 'paid' && (
                                                        <Button size="xs" variant="ghost" aria-label="Переоткрыть" title="Переоткрыть новой версией" onClick={() => { setReopenFor(c.id); setReopenComment(''); }}><LuRotateCcw /></Button>
                                                    )}
                                                </HStack>
                                            )}
                                        </Table.Cell>
                                    </Table.Row>
                                );
                            })}
                        </Table.Body>
                    </Table.Root>
                </Box>

                {reopenFor !== null && (
                    <Box bg="bg.panel" borderWidth="2px" borderColor="orange.solid" borderRadius="xl" p={4}>
                        <Text fontWeight="700" mb={1}>Переоткрыть расчёт новой версией</Text>
                        <Text fontSize="xs" color="fg.muted" mb={2}>Прежняя версия останется в истории; основание попадёт в комментарий новой версии.</Text>
                        <HStack gap={2} align="end" flexWrap="wrap">
                            <input style={{ ...inputStyle, flex: 1, minWidth: '280px' }} value={reopenComment} aria-label="Основание переоткрытия" placeholder="Ошибка в данных учётной системы: …" onChange={(e) => setReopenComment(e.target.value)} />
                            <Button size="sm" variant="ghost" onClick={() => setReopenFor(null)}>Отмена</Button>
                            <Button size="sm" colorPalette="orange" loading={busy} disabled={reopenComment.trim().length < 5} onClick={async () => { if (await call('post', `/crm/motivation/calculations/${reopenFor}/reopen`, { comment: reopenComment })) setReopenFor(null); }}>Переоткрыть</Button>
                        </HStack>
                    </Box>
                )}

                <SimpleGrid columns={{ base: 1, xl: 2 }} gap={4} alignItems="start">
                    <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
                        <Text fontWeight="700" mb={1}>Разовые корректировки (п. 6.7)</Text>
                        <Text fontSize="xs" color="fg.muted" mb={3}>В пределах {fmtPercent(data.adjustment_limit, 0)} переменной части работника. Превышение блокируется.</Text>
                        {data.can_edit && (
                            <VStack align="stretch" gap={2} mb={3}>
                                <HStack gap={2} flexWrap="wrap">
                                    <select aria-label="Работник" style={selectStyle} value={correction.manager_id} onChange={(e) => setCorrection({ ...correction, manager_id: e.target.value })}>
                                        <option value="">Работник…</option>
                                        {data.rows.filter((r) => r.calculation.status === 'draft').map((r) => <option key={r.manager.id} value={r.manager.id}>{r.manager.name}</option>)}
                                    </select>
                                    <input type="number" step="100" style={inputStyle} value={correction.amount} aria-label="Сумма со знаком" placeholder="Сумма, ± ₽" onChange={(e) => setCorrection({ ...correction, amount: e.target.value })} />
                                </HStack>
                                <Textarea rows={2} value={correction.reason} aria-label="Основание корректировки" placeholder="Основание: замещающий привлёк партнёра (п. 10.4)…" onChange={(e) => setCorrection({ ...correction, reason: e.target.value })} />
                                <HStack justify="flex-end">
                                    <Button size="sm" loading={busy} disabled={!correction.manager_id || !correction.amount || correction.reason.trim().length < 5} onClick={async () => { if (await call('post', '/crm/motivation/adjustments', { manager_id: Number(correction.manager_id), amount: Number(correction.amount), reason: correction.reason })) setCorrection({ manager_id: '', amount: '', reason: '' }); }}>Внести</Button>
                                </HStack>
                            </VStack>
                        )}
                        {data.corrections.length === 0
                            ? <Text fontSize="sm" color="fg.subtle">Корректировок за месяц нет.</Text>
                            : (
                                <VStack align="stretch" gap={1} divideY="1px" divideColor="border">
                                    {data.corrections.map((a) => {
                                        const row = data.rows.find((r) => r.manager.id === a.manager_id);
                                        return (
                                            <HStack key={a.id} py={1.5} gap={2} align="start">
                                                <Box flex="1">
                                                    <Text fontSize="sm"><Text as="span" fontWeight="600">{row?.manager.name ?? a.manager_id}</Text> · {a.reason}</Text>
                                                    <Text fontSize="xs" color="fg.subtle">{a.author ?? ''} · {fmtDateTime(a.created_at)}</Text>
                                                </Box>
                                                <Text fontSize="sm" fontWeight="700" color={a.amount > 0 ? 'green.fg' : 'red.fg'} fontVariantNumeric="tabular-nums">{fmtSigned(a.amount)}</Text>
                                                {data.can_edit && row?.calculation.status === 'draft' && (
                                                    <Button size="xs" variant="ghost" colorPalette="red" aria-label="Удалить корректировку" loading={busy} onClick={() => call('delete', `/crm/motivation/adjustments/${a.id}`)}><LuTrash2 /></Button>
                                                )}
                                            </HStack>
                                        );
                                    })}
                                </VStack>
                            )}
                    </Box>

                    <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
                        <Text fontWeight="700" mb={1}>Возражения работников (п. 11.3)</Text>
                        <Text fontSize="xs" color="fg.muted" mb={3}>Принять — месяц переоткроется новой версией; отклонить — только с обоснованием.</Text>
                        {data.objections.length === 0
                            ? <Text fontSize="sm" color="fg.subtle">Возражений по расчётам месяца нет.</Text>
                            : (
                                <VStack align="stretch" gap={2}>
                                    {data.objections.map((o) => (
                                        <Box key={o.id} borderWidth="1px" borderColor="border" borderRadius="lg" p={3}>
                                            <HStack justify="space-between" mb={1} flexWrap="wrap">
                                                <HStack gap={2}>
                                                    <Text fontSize="sm" fontWeight="600">{o.manager}</Text>
                                                    <Badge colorPalette={OBJECTION_PALETTE[o.status] ?? 'gray'} variant="subtle" size="sm">{o.status_label}</Badge>
                                                    {o.version > 1 && <Text fontSize="xs" color="fg.subtle">версия {o.version}</Text>}
                                                </HStack>
                                                <Text fontSize="xs" color="fg.subtle">{fmtDateTime(o.created_at)}</Text>
                                            </HStack>
                                            <Text fontSize="sm">{o.reason}</Text>
                                            {o.response && <Text fontSize="sm" color="fg.muted" mt={1}>Ответ: {o.response}</Text>}
                                            {o.status === 'open' && data.can_edit && (
                                                <VStack align="stretch" gap={2} mt={2}>
                                                    <Textarea rows={2} value={responding[o.id] ?? ''} aria-label="Ответ работнику" placeholder="Ответ работнику" onChange={(e) => setResponding({ ...responding, [o.id]: e.target.value })} />
                                                    <HStack justify="flex-end" gap={2}>
                                                        <Button size="xs" variant="outline" loading={busy} disabled={(responding[o.id] ?? '').trim().length < 5} onClick={() => call('post', `/crm/motivation/objections/${o.id}/respond`, { decision: 'rejected', response: responding[o.id] })}>Отклонить с обоснованием</Button>
                                                        <Button size="xs" colorPalette="green" loading={busy} onClick={() => call('post', `/crm/motivation/objections/${o.id}/respond`, { decision: 'accepted', response: responding[o.id] })}>Принять и переоткрыть</Button>
                                                    </HStack>
                                                </VStack>
                                            )}
                                        </Box>
                                    ))}
                                </VStack>
                            )}
                    </Box>
                </SimpleGrid>

                {!allReady && (
                    <Alert status="info" title="Утвердить можно и при невыполненных условиях">
                        Условия готовности — подсказка, а не блокировка: по неразобранным накладным вычет зафиксируется по состоянию «не погашено», а возражения после утверждения будут разбираться переоткрытием.
                    </Alert>
                )}
            </VStack>
        </CrmLayout>
    );
}
