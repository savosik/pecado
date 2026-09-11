import { useEffect, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { Badge, Box, HStack, SimpleGrid, Table, Text, VStack } from '@chakra-ui/react';
import { LuShieldBan, LuUndo2 } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import MetricHint from '@/Crm/Components/MetricHint';
import { toastError, toastSuccess } from '@/utils/toast';
import { fmtDay, fmtRub0, plural } from '../Salary/components/format';

const inputStyle = {
    padding: '0.45rem 0.6rem',
    borderRadius: '0.5rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '150px',
    background: 'var(--chakra-colors-bg-panel)',
    color: 'var(--chakra-colors-fg)',
};

const EMPTY_FORM = { reason: 'legal', excluded_from: '', excluded_until: '', document_ref: '', comment: '' };

/**
 * Исключения задолженности: какие долги выведены из расчёта и на каком основании.
 *
 * Кандидаты отсортированы по цене в месяц: разбирать список стоит с того, что
 * дороже всего обходится работнику. Возврат долга в расчёт — датой, не удалением.
 */
export default function MotivationDebtExclusions(props) {
    const [data, setData] = useState(props);
    const [busy, setBusy] = useState(false);
    const [target, setTarget] = useState(null);
    const [form, setForm] = useState(EMPTY_FORM);
    const [closing, setClosing] = useState({});

    useEffect(() => { setData(props); }, [props]);

    const navigate = (changes) => {
        const params = { ...data.query, ...changes };
        Object.keys(params).forEach((k) => { if (params[k] === '' || params[k] === null || params[k] === undefined) delete params[k]; });
        router.get('/crm/motivation/debt-exclusions', params, { preserveState: true, preserveScroll: true, replace: true });
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

    const startExclude = (c, wholePartner) => {
        setTarget({ ...c, whole: wholePartner });
        setForm({ ...EMPTY_FORM, excluded_from: new Date().toISOString().slice(0, 10) });
    };

    const submit = async () => {
        const payload = { ...form, shipment_id: target.whole ? null : target.shipment_id, user_id: target.partner_id };
        if (await call('post', '/crm/motivation/debt-exclusions', payload)) { setTarget(null); setForm(EMPTY_FORM); }
    };

    const active = data.exclusions.filter((e) => e.active);
    const history = data.exclusions.filter((e) => !e.active);

    return (
        <CrmLayout breadcrumbs={[{ label: 'Мотивация' }, { label: 'Исключения долгов' }]}>
            <Head title="Исключения задолженности — CRM" />
            <PageHeader title="Исключения задолженности" description="Какие долги выведены из расчёта и на каком основании. Исключение действует с указанной даты и не меняет утверждённые расчёты." />

            <VStack align="stretch" gap={4}>
                <SimpleGrid columns={{ base: 2, md: 4 }} gap={3}>
                    <Stat label={`Кандидатов старше ${data.older_than_days} дн.`} value={String(data.summary.invoices)} hint={`${data.summary.partners} ${plural(data.summary.partners, 'партнёр', 'партнёра', 'партнёров')}`} />
                    <Stat label="Остаток по ним" value={fmtRub0(data.summary.amount)} />
                    <Stat label="Обходится в месяц" value={fmtRub0(data.summary.monthly_cost)} hint={`ставка ${(data.rate_per_day * 100).toLocaleString('ru-RU')} % в день × ${data.days_in_month} дн.`} />
                    <Stat label="Действующих исключений" value={String(active.length)} />
                </SimpleGrid>

                <HStack gap={2} flexWrap="wrap">
                    <select aria-label="Работник" style={inputStyle} value={data.query.manager ?? ''} onChange={(e) => navigate({ manager: e.target.value || undefined })}>
                        <option value="">Все работники</option>
                        {data.managers.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                    </select>
                    <select aria-label="Срок просрочки" style={inputStyle} value={data.older_than_days} onChange={(e) => navigate({ older_than: e.target.value })}>
                        {[30, 60, 90, 120, 180].map((d) => <option key={d} value={d}>просрочены дольше {d} дн.</option>)}
                    </select>
                </HStack>

                {target && data.can_edit && (
                    <Box bg="bg.panel" borderWidth="2px" borderColor="orange.solid" borderRadius="xl" p={4}>
                        <Text fontWeight="700" mb={2}>
                            Исключить {target.whole ? `все долги партнёра «${target.partner_name}»` : `накладную ${target.number} партнёра «${target.partner_name}»`}
                        </Text>
                        <SimpleGrid columns={{ base: 1, md: 5 }} gap={3}>
                            <label style={{ fontSize: '0.75rem' }}>Основание<br />
                                <select style={inputStyle} value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })}>
                                    {Object.entries(data.reasons).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                                </select>
                            </label>
                            <label style={{ fontSize: '0.75rem' }}>С даты<br /><input type="date" style={inputStyle} value={form.excluded_from} onChange={(e) => setForm({ ...form, excluded_from: e.target.value })} /></label>
                            <label style={{ fontSize: '0.75rem' }}>По дату (пусто — бессрочно)<br /><input type="date" style={inputStyle} value={form.excluded_until} onChange={(e) => setForm({ ...form, excluded_until: e.target.value })} /></label>
                            <label style={{ fontSize: '0.75rem' }}>Документ-основание<br /><input style={inputStyle} placeholder="Приказ, претензия, дело…" value={form.document_ref} onChange={(e) => setForm({ ...form, document_ref: e.target.value })} /></label>
                            <label style={{ fontSize: '0.75rem' }}>Пояснение<br /><input style={inputStyle} value={form.comment} onChange={(e) => setForm({ ...form, comment: e.target.value })} /></label>
                        </SimpleGrid>
                        <HStack mt={3} justify="flex-end" gap={2}>
                            <Button size="sm" variant="ghost" onClick={() => setTarget(null)}>Отмена</Button>
                            <Button size="sm" colorPalette="orange" loading={busy} disabled={!form.excluded_from || !form.document_ref} onClick={submit}><LuShieldBan /> Исключить из расчёта</Button>
                        </HStack>
                    </Box>
                )}

                <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflowX="auto">
                    <HStack px={4} pt={3} gap={1}><Text fontWeight="700">Кандидаты на расчистку</Text><MetricHint text="Открытые накладные, просроченные дольше выбранного срока, без действующего исключения. «В месяц» — остаток × ставка К1 × дни текущего месяца: столько накладная снимает с переменной части работника, пока висит." /></HStack>
                    {data.candidates.length === 0 ? <Text px={4} pb={4} pt={2} fontSize="sm" color="fg.muted">Долгов старше {data.older_than_days} дней без исключения нет.</Text> : (
                        <Table.Root size="sm" mt={2}>
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeader>Партнёр</Table.ColumnHeader>
                                    <Table.ColumnHeader>Накладная</Table.ColumnHeader>
                                    <Table.ColumnHeader>Работник</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Остаток</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Просрочка</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">В месяц</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Действия</Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {data.candidates.map((c) => (
                                    <Table.Row key={c.invoice_id}>
                                        <Table.Cell><Text fontSize="sm" fontWeight="600">{c.partner_name}</Text></Table.Cell>
                                        <Table.Cell><Text fontSize="sm">{c.number}</Text><Text fontSize="xs" color="fg.subtle">отгружена {fmtDay(c.shipped_on)} · срок {fmtDay(c.due_on)}{c.needs_review ? ' · дата оплаты не восстановлена' : ''}</Text></Table.Cell>
                                        <Table.Cell><Text fontSize="sm">{c.manager.name}</Text></Table.Cell>
                                        <Table.Cell textAlign="right"><Text fontSize="sm">{fmtRub0(c.balance)}</Text></Table.Cell>
                                        <Table.Cell textAlign="right"><Text fontSize="sm" color={c.overdue_days >= 90 ? 'red.fg' : undefined}>{c.overdue_days} дн.</Text></Table.Cell>
                                        <Table.Cell textAlign="right"><Text fontSize="sm" fontWeight="700">{fmtRub0(c.monthly_cost)}</Text></Table.Cell>
                                        <Table.Cell textAlign="right">
                                            {data.can_edit && (
                                                <HStack justify="flex-end" gap={1}>
                                                    <Button size="xs" variant="outline" onClick={() => startExclude(c, false)}>Накладную</Button>
                                                    <Button size="xs" variant="ghost" onClick={() => startExclude(c, true)}>Партнёра целиком</Button>
                                                </HStack>
                                            )}
                                        </Table.Cell>
                                    </Table.Row>
                                ))}
                            </Table.Body>
                        </Table.Root>
                    )}
                </Box>

                <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflowX="auto">
                    <HStack px={4} pt={3} gap={1}><Text fontWeight="700">Действующие исключения</Text><MetricHint text="Вернуть долг в расчёт — закрыть исключение датой: начисление возобновится со следующего дня, история сохранится." /></HStack>
                    {active.length === 0 ? <Text px={4} pb={4} pt={2} fontSize="sm" color="fg.muted">Действующих исключений нет.</Text> : (
                        <ExclusionsTable rows={active} canEdit={data.can_edit} closing={closing} setClosing={setClosing} busy={busy} onClose={(row) => call('patch', `/crm/motivation/debt-exclusions/${row.id}`, { excluded_until: closing[row.id] })} />
                    )}
                </Box>

                {history.length > 0 && (
                    <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflowX="auto">
                        <Text fontWeight="700" px={4} pt={3}>История</Text>
                        <ExclusionsTable rows={history} canEdit={false} />
                    </Box>
                )}

                <Alert status="info" title="Что видит работник">Исключённые долги показаны на его экране «Долги» с основанием; вычет по ним не начисляется со дня исключения. Утверждённые месяцы не пересчитываются.</Alert>
            </VStack>
        </CrmLayout>
    );
}

function ExclusionsTable({ rows, canEdit, closing = {}, setClosing = () => {}, busy = false, onClose = () => {} }) {
    return (
        <Table.Root size="sm" mt={2}>
            <Table.Header>
                <Table.Row>
                    <Table.ColumnHeader>Партнёр</Table.ColumnHeader>
                    <Table.ColumnHeader>Документ</Table.ColumnHeader>
                    <Table.ColumnHeader>Основание</Table.ColumnHeader>
                    <Table.ColumnHeader>Период</Table.ColumnHeader>
                    <Table.ColumnHeader textAlign="right">Сумма</Table.ColumnHeader>
                    <Table.ColumnHeader>Кто</Table.ColumnHeader>
                    {canEdit && <Table.ColumnHeader textAlign="right">Вернуть в расчёт</Table.ColumnHeader>}
                </Table.Row>
            </Table.Header>
            <Table.Body>
                {rows.map((r) => (
                    <Table.Row key={r.id}>
                        <Table.Cell><Text fontSize="sm" fontWeight="600">{r.partner_name}</Text></Table.Cell>
                        <Table.Cell><Text fontSize="sm">{r.number ?? 'все долги партнёра'}</Text></Table.Cell>
                        <Table.Cell>
                            <HStack gap={2}><Badge size="xs" variant="subtle" colorPalette={r.active ? 'orange' : 'gray'}>{r.reason_label}</Badge><Text fontSize="xs" color="fg.muted">{r.document_ref}</Text></HStack>
                            {r.comment && <Text fontSize="xs" color="fg.subtle">{r.comment}</Text>}
                        </Table.Cell>
                        <Table.Cell><Text fontSize="xs">{fmtDay(r.excluded_from)} — {r.excluded_until ? fmtDay(r.excluded_until) : 'бессрочно'}</Text><Text fontSize="xs" color="fg.subtle">{r.status_label}</Text></Table.Cell>
                        <Table.Cell textAlign="right"><Text fontSize="sm">{r.amount === null ? 'весь остаток' : fmtRub0(r.amount)}</Text></Table.Cell>
                        <Table.Cell><Text fontSize="xs" color="fg.muted">{r.author ?? '—'}</Text></Table.Cell>
                        {canEdit && (
                            <Table.Cell textAlign="right">
                                <HStack justify="flex-end" gap={1}>
                                    <input type="date" aria-label="Дата возврата" style={{ ...inputStyle, minWidth: '140px', padding: '0.25rem 0.4rem' }} min={r.excluded_from} value={closing[r.id] ?? ''} onChange={(e) => setClosing({ ...closing, [r.id]: e.target.value })} />
                                    <Button size="xs" variant="outline" loading={busy} disabled={!closing[r.id]} onClick={() => onClose(r)}><LuUndo2 /> Вернуть</Button>
                                </HStack>
                            </Table.Cell>
                        )}
                    </Table.Row>
                ))}
            </Table.Body>
        </Table.Root>
    );
}

function Stat({ label, value, hint }) {
    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
            <Text fontSize="xs" color="fg.muted">{label}</Text>
            <Text fontSize="2xl" fontWeight="800" fontVariantNumeric="tabular-nums">{value}</Text>
            {hint && <Text fontSize="xs" color="fg.subtle">{hint}</Text>}
        </Box>
    );
}
