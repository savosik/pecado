import { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Badge, Box, HStack, Table, Text, Textarea, VStack } from '@chakra-ui/react';
import { LuChevronDown, LuChevronRight, LuDownload, LuLock } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { fmtDateTime, fmtDay, fmtRub, fmtSigned, plural } from '../Salary/components/format';

const selectStyle = {
    padding: '0.45rem 0.6rem',
    borderRadius: '0.5rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '180px',
    background: 'var(--chakra-colors-bg-panel)',
    color: 'var(--chakra-colors-fg)',
};

const STATUS_PALETTE = { draft: 'blue', approved: 'green', paid: 'gray' };
const OBJECTION_PALETTE = { open: 'orange', accepted: 'green', rejected: 'gray' };

/**
 * Расчётный лист: из чего именно сложился доход и на каком основании.
 *
 * Читается из снимка, версии переключаются, после утверждения не меняется.
 * Отгрузки на экране свёрнуты по партнёрам — в PDF выводится полный перечень.
 */
export default function MotivationPayslip({ month, month_label: monthLabel, months, manager, scope_options: scopeOptions, can_see_all: canSeeAll, is_own: isOwn, slip }) {
    const navigate = (changes) => {
        const params = { month, ...changes };
        if (canSeeAll && manager?.id && !('manager' in changes)) params.manager = manager.id;
        Object.keys(params).forEach((k) => { if (params[k] === '' || params[k] === null || params[k] === undefined) delete params[k]; });
        router.get('/crm/motivation/payslip', params, { preserveState: true, preserveScroll: true, replace: true });
    };

    const calc = slip?.calculation;
    const pdfParams = new URLSearchParams({ month });
    if (canSeeAll && manager?.id) pdfParams.set('manager', String(manager.id));
    if (calc) pdfParams.set('version', String(calc.version));

    return (
        <CrmLayout breadcrumbs={[{ label: 'Продажи' }, { label: 'Моя мотивация', href: '/crm/motivation' }, { label: 'Расчётный лист' }]}>
            <Head title="Расчётный лист — CRM" />
            <PageHeader
                title={canSeeAll && manager ? `Расчётный лист: ${manager.name}` : 'Расчётный лист'}
                description="Из чего именно сложился доход и на каком основании."
                actions={(
                    <HStack gap={2} flexWrap="wrap">
                        <select aria-label="Месяц" style={selectStyle} value={month} onChange={(e) => navigate({ month: e.target.value, version: undefined })}>
                            {(months ?? []).map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
                        </select>
                        {canSeeAll && (scopeOptions ?? []).length > 0 && (
                            <select aria-label="Работник" style={selectStyle} value={manager?.id ?? ''} onChange={(e) => navigate({ manager: e.target.value, version: undefined })}>
                                <option value="">Выберите работника…</option>
                                {scopeOptions.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                            </select>
                        )}
                        {slip && (
                            <Button size="sm" variant="outline" asChild>
                                <a href={`/crm/motivation/payslip/pdf?${pdfParams.toString()}`}><LuDownload /> PDF</a>
                            </Button>
                        )}
                    </HStack>
                )}
            />

            <VStack align="stretch" gap={4} maxW="1100px">
                {manager === null && (
                    <Alert status="info" title="Карточка работника не привязана">Выберите работника выше или обратитесь к руководителю.</Alert>
                )}

                {slip && !calc.on_scheme_v2 && (
                    <Alert status="info" title="Этот месяц считается по прежней схеме">
                        Расчётный лист за него — в разделе <Link href={`/crm/salary?month=${month}`}><u>«Моя зарплата»</u></Link>.
                    </Alert>
                )}

                {slip && calc.on_scheme_v2 && (
                    <>
                        <HStack justify="space-between" flexWrap="wrap" gap={2}>
                            <HStack gap={2}>
                                <Badge colorPalette={STATUS_PALETTE[calc.status] ?? 'blue'} variant="subtle">
                                    {calc.frozen && <LuLock size={11} />} {calc.status_label} · версия {calc.version}
                                </Badge>
                                <Text fontSize="sm" color="fg.muted">
                                    {calc.frozen
                                        ? `утверждён ${fmtDateTime(calc.approved_at)}`
                                        : 'предварительный: данные могут измениться до утверждения'}
                                </Text>
                            </HStack>
                            {slip.versions.length > 1 && (
                                <select aria-label="Версия расчёта" style={selectStyle} value={calc.version} onChange={(e) => navigate({ version: e.target.value })}>
                                    {slip.versions.map((v) => (
                                        <option key={v.id} value={v.version}>версия {v.version} · {v.status_label} · {fmtRub(v.total, 0)}</option>
                                    ))}
                                </select>
                            )}
                        </HStack>

                        {calc.comment && <Text fontSize="sm" color="fg.muted">Комментарий руководителя: {calc.comment}</Text>}

                        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflowX="auto">
                            <Table.Root size="sm">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>Начисление</Table.ColumnHeader>
                                        <Table.ColumnHeader>Пункт</Table.ColumnHeader>
                                        <Table.ColumnHeader>Как получено</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">Сумма</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {slip.lines.map((line) => (
                                        <Table.Row key={line.key}>
                                            <Table.Cell><Text fontSize="sm" fontWeight="600">{line.label}</Text></Table.Cell>
                                            <Table.Cell><Text fontSize="xs" color="fg.subtle">{line.clause}</Text></Table.Cell>
                                            <Table.Cell><Text fontSize="xs" color="fg.muted">{line.explanation}</Text></Table.Cell>
                                            <Table.Cell textAlign="right"><Text fontSize="sm" fontVariantNumeric="tabular-nums" color={line.amount < 0 ? 'red.fg' : undefined}>{line.amount < 0 ? fmtSigned(line.amount) : fmtRub(line.amount)}</Text></Table.Cell>
                                        </Table.Row>
                                    ))}
                                    <Table.Row bg="bg.subtle">
                                        <Table.Cell colSpan={3}><Text fontWeight="800">Итого к начислению</Text></Table.Cell>
                                        <Table.Cell textAlign="right"><Text fontWeight="800" fontVariantNumeric="tabular-nums">{fmtRub(calc.total)}</Text></Table.Cell>
                                    </Table.Row>
                                </Table.Body>
                            </Table.Root>
                        </Box>

                        {slip.quarterly_share && (
                            <Section title={`Квартальная премия отдела · ${slip.quarterly_share.quarter_label} (п. 7.6)`}>
                                <HStack justify="space-between" fontSize="sm" py={1} flexWrap="wrap" gap={2}>
                                    <Text>
                                        Премия отдела {fmtRub(slip.quarterly_share.bonus_amount)} ({slip.quarterly_share.status_label}, ступень {slip.quarterly_share.step}).
                                        {slip.quarterly_share.distributed ? ` Ваша доля${slip.quarterly_share.reason ? ` — ${slip.quarterly_share.reason}` : ''}.` : ' Распределение между работниками ещё не сделано.'}
                                    </Text>
                                    <Text fontWeight="700" fontVariantNumeric="tabular-nums">{fmtRub(slip.quarterly_share.amount)}</Text>
                                </HStack>
                                <Text fontSize="xs" color="fg.muted">В переменную часть и в итог месяца не входит: премия начисляется на отдел и выплачивается своим порядком.</Text>
                            </Section>
                        )}

                        <Shipments title="Отгрузки закреплённой базы (П1)" groups={slip.shipments.base} />
                        <Shipments title="Отгрузки новым партнёрам (П2)" groups={slip.shipments.new} />

                        {slip.returns.length > 0 && (
                            <Section title="Возвраты периода">
                                {slip.returns.map((r) => (
                                    <HStack key={r.return_id} justify="space-between" fontSize="sm" py={1}>
                                        <Text>{r.number ?? '—'} · {r.partner_name} · {fmtDay(r.date)}</Text>
                                        <Text color="red.fg" fontVariantNumeric="tabular-nums">−{fmtRub(r.amount)}</Text>
                                    </HStack>
                                ))}
                            </Section>
                        )}

                        {slip.overdue.length > 0 && (
                            <Section title={`Просроченная задолженность (К1) · ${slip.overdue.length} ${plural(slip.overdue.length, 'накладная', 'накладные', 'накладных')}`}>
                                <Text fontSize="xs" color="fg.muted" mb={2}>Полный перечень по партнёрам и накладным — на экране «Долги»; здесь — состав вычета.</Text>
                                {slip.overdue.slice(0, 20).map((r) => (
                                    <HStack key={r.invoice_id} justify="space-between" fontSize="sm" py={1}>
                                        <Text>{r.number} · {r.partner_name} · {r.days} дн.{r.needs_review ? ' · дата не восстановлена' : ''}</Text>
                                        <Text fontVariantNumeric="tabular-nums" color="fg.muted">{fmtRub(r.integral, 0)} ₽·дн.</Text>
                                    </HStack>
                                ))}
                                {slip.overdue.length > 20 && <Text fontSize="xs" color="fg.subtle">…и ещё {slip.overdue.length - 20}. Полный перечень — в PDF.</Text>}
                            </Section>
                        )}

                        {slip.corrections.length > 0 && (
                            <Section title="Корректировки руководителя (п. 6.7)">
                                {slip.corrections.map((c, i) => (
                                    <HStack key={c.id ?? i} justify="space-between" fontSize="sm" py={1}>
                                        <Text>{c.comment ?? c.title ?? ''}</Text>
                                        <Text fontVariantNumeric="tabular-nums" color={c.amount < 0 ? 'red.fg' : undefined}>{fmtSigned(c.amount)}</Text>
                                    </HStack>
                                ))}
                            </Section>
                        )}

                        <Objection slip={slip} isOwn={isOwn} />
                    </>
                )}
            </VStack>
        </CrmLayout>
    );
}

function Section({ title, children }) {
    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={{ base: 4, md: 5 }}>
            <Text fontWeight="700" mb={2}>{title}</Text>
            {children}
        </Box>
    );
}

function Shipments({ title, groups }) {
    const [open, setOpen] = useState(() => new Set());

    if (!groups || groups.length === 0) return null;

    const toggle = (id) => setOpen((prev) => { const n = new Set(prev); if (n.has(id)) n.delete(id); else n.add(id); return n; });
    const total = groups.reduce((s, g) => s + Number(g.amount), 0);
    const docs = groups.reduce((s, g) => s + g.documents.length, 0);

    return (
        <Section title={`${title} · ${groups.length} ${plural(groups.length, 'партнёр', 'партнёра', 'партнёров')}, ${docs} ${plural(docs, 'документ', 'документа', 'документов')}`}>
            <VStack align="stretch" gap={0} divideY="1px" divideColor="border">
                {groups.map((g) => (
                    <Box key={g.partner_id}>
                        <HStack as="button" type="button" w="100%" py={1.5} gap={2} textAlign="left" cursor="pointer" onClick={() => toggle(g.partner_id)} aria-expanded={open.has(g.partner_id)}>
                            <Box color="fg.subtle">{open.has(g.partner_id) ? <LuChevronDown size={14} /> : <LuChevronRight size={14} />}</Box>
                            <Text fontSize="sm" flex="1">{g.partner_name} <Text as="span" color="fg.subtle">· {g.documents.length}</Text></Text>
                            <Text fontSize="sm" fontWeight="600" fontVariantNumeric="tabular-nums">{fmtRub(g.amount)}</Text>
                        </HStack>
                        {open.has(g.partner_id) && (
                            <VStack align="stretch" gap={0} pl={6} pb={2}>
                                {g.documents.map((d) => (
                                    <HStack key={`${d.number}-${d.date}`} justify="space-between" fontSize="xs" color="fg.muted" py={0.5}>
                                        <Text>{d.number} · {fmtDay(d.date)}</Text>
                                        <Text fontVariantNumeric="tabular-nums">{fmtRub(d.amount)}</Text>
                                    </HStack>
                                ))}
                            </VStack>
                        )}
                    </Box>
                ))}
                <HStack justify="space-between" pt={2} fontSize="sm">
                    <Text color="fg.muted">Итого по документам</Text>
                    <Text fontWeight="700" fontVariantNumeric="tabular-nums">{fmtRub(total)}</Text>
                </HStack>
            </VStack>
        </Section>
    );
}

function Objection({ slip, isOwn }) {
    const state = slip.objection;
    const form = useForm({ calculation: slip.calculation.id, reason: '' });
    const [sent, setSent] = useState(false);

    const submit = (e) => {
        e.preventDefault();
        form.post('/crm/motivation/objection', { preserveScroll: true, onSuccess: () => { setSent(true); form.reset('reason'); } });
    };

    return (
        <Section title="Возражение по расчёту (п. 11.3)">
            {state.items.length > 0 && (
                <VStack align="stretch" gap={2} mb={3}>
                    {state.items.map((item) => (
                        <Box key={item.id} borderWidth="1px" borderColor="border" borderRadius="lg" p={3}>
                            <HStack justify="space-between" mb={1}>
                                <Badge colorPalette={OBJECTION_PALETTE[item.status] ?? 'gray'} variant="subtle" size="sm">{item.status_label}</Badge>
                                <Text fontSize="xs" color="fg.subtle">подано {fmtDateTime(item.created_at)}</Text>
                            </HStack>
                            <Text fontSize="sm">{item.reason}</Text>
                            {item.response && <Text fontSize="sm" color="fg.muted" mt={1}>Ответ руководителя: {item.response}</Text>}
                        </Box>
                    ))}
                </VStack>
            )}

            {sent && <Alert status="success" title="Возражение подано">Руководитель ответит на него или переоткроет расчёт новой версией.</Alert>}

            {!sent && state.can_object && isOwn && (
                <form onSubmit={submit}>
                    <VStack align="stretch" gap={2}>
                        <Text fontSize="sm" color="fg.muted">Возражение принимается до {fmtDay(state.deadline_on)}. Оно не меняет сумму само по себе: руководитель либо переоткроет расчёт, либо ответит с обоснованием.</Text>
                        <Textarea
                            value={form.data.reason}
                            onChange={(e) => form.setData('reason', e.target.value)}
                            placeholder="Что именно неверно: какая отгрузка, накладная или партнёр"
                            rows={3}
                            aria-label="Причина возражения"
                        />
                        {form.errors.reason && <Text fontSize="sm" color="red.fg">{form.errors.reason}</Text>}
                        <HStack justify="flex-end">
                            <Button size="sm" type="submit" loading={form.processing} disabled={form.data.reason.trim().length < 10}>Подать возражение</Button>
                        </HStack>
                    </VStack>
                </form>
            )}

            {!sent && !state.can_object && (
                <Text fontSize="sm" color="fg.muted">{state.reason ?? 'Возражение сейчас недоступно.'}</Text>
            )}
            {!sent && state.can_object && !isOwn && (
                <Text fontSize="sm" color="fg.muted">Возражение подаёт сам работник по своему расчёту.</Text>
            )}
        </Section>
    );
}
