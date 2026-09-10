import { useEffect, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Badge, Box, HStack, SimpleGrid, Table, Text, VStack } from '@chakra-ui/react';
import { LuChevronDown, LuChevronRight, LuListChecks, LuLock } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Alert } from '@/components/ui/alert';
import MetricHint from '@/Crm/Components/MetricHint';
import RowActions from '@/shared/Panel/RowActions';
import TaskDialog from '@/Crm/Components/TaskDialog';
import { fmtDay, fmtRub, fmtRub0, plural } from '../Salary/components/format';

const selectStyle = {
    padding: '0.45rem 0.6rem',
    borderRadius: '0.5rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '180px',
    background: 'var(--chakra-colors-bg-panel)',
    color: 'var(--chakra-colors-fg)',
};

const LEVEL = {
    overdue: { label: 'просрочка', palette: 'orange' },
    no_preorders: { label: 'без предзаказов', palette: 'red' },
    no_orders: { label: 'без заказов', palette: 'red' },
    hold: { label: 'стоп', palette: 'red' },
};

/**
 * «Долги: во что они обходятся» — какой долг стоит мне дороже всего прямо сейчас.
 *
 * Партнёр сверху, накладные внутри. Отдельный раздел — выведенные из расчёта:
 * работник должен понимать, почему долг виден, а вычета по нему нет.
 */
export default function MotivationDebts({ month, month_label: monthLabel, manager, scope_options: scopeOptions, can_see_all: canSeeAll, focus_partner: focusPartner, debts }) {
    const [taskFor, setTaskFor] = useState(null);
    const [open, setOpen] = useState(() => new Set(focusPartner ? [Number(focusPartner)] : []));

    useEffect(() => {
        if (focusPartner) setOpen((prev) => new Set([...prev, Number(focusPartner)]));
    }, [focusPartner]);

    const navigate = (changes) => {
        const params = { month, ...changes };
        if (canSeeAll && manager?.id && !('manager' in changes)) params.manager = manager.id;
        Object.keys(params).forEach((k) => { if (params[k] === '' || params[k] === null || params[k] === undefined) delete params[k]; });
        router.get('/crm/motivation/debts', params, { preserveState: true, preserveScroll: true, replace: true });
    };

    const toggle = (id) => setOpen((prev) => {
        const next = new Set(prev);
        if (next.has(id)) next.delete(id); else next.add(id);
        return next;
    });

    const summary = debts?.summary;
    const partners = debts?.partners ?? [];
    const excluded = debts?.excluded ?? [];

    return (
        <CrmLayout breadcrumbs={[{ label: 'Продажи' }, { label: 'Моя мотивация', href: '/crm/motivation' }, { label: 'Долги' }]}>
            <Head title="Долги — CRM" />
            <PageHeader
                title={canSeeAll && manager ? `Долги: ${manager.name}` : 'Долги: во что они обходятся'}
                description="Какой долг стоит вам дороже всего прямо сейчас. Начисление идёт по дням и прекращается в день оплаты."
                actions={canSeeAll && (scopeOptions ?? []).length > 0 ? (
                    <select aria-label="Работник" style={selectStyle} value={manager?.id ?? ''} onChange={(e) => navigate({ manager: e.target.value })}>
                        <option value="">Выберите работника…</option>
                        {scopeOptions.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                    </select>
                ) : null}
            />

            <VStack align="stretch" gap={4}>
                {manager === null && (
                    <Alert status="info" title="Карточка работника не привязана">Выберите работника выше или обратитесь к руководителю.</Alert>
                )}

                {debts && (
                    <>
                        {debts.frozen && (
                            <HStack fontSize="sm" color="fg.muted" gap={2}>
                                <LuLock size={14} />
                                <Text>Месяц утверждён: показан состав вычета из снимка расчёта, а не текущее состояние долгов.</Text>
                            </HStack>
                        )}

                        <SimpleGrid columns={{ base: 1, sm: 3 }} gap={3}>
                            <Stat label="Просрочено на конец периода" value={fmtRub0(summary.overdue_total)} hint="Остаток просроченной задолженности по накладным, вошедшим в базу начисления." />
                            <Stat label="Стоит вам в день" value={`−${fmtRub0(summary.daily_cost)}`} tone="orange" hint={`Остаток × ставка ${(debts.rate_per_day * 100).toLocaleString('ru-RU', { maximumFractionDigits: 3 })} % за календарный день.`} />
                            <Stat label={`Вычтено за ${monthLabel.toLowerCase()}`} value={`−${fmtRub0(summary.deducted_this_month)}`} tone="red" hint="Показатель К1 расчёта: сумма «остаток × дни просрочки» по каждой накладной, умноженная на ставку." />
                        </SimpleGrid>

                        {summary.needs_review_count > 0 && (
                            <Alert status="warning" title={`Дата погашения не восстановлена у ${summary.needs_review_count} ${plural(summary.needs_review_count, 'накладной', 'накладных', 'накладных')}`}>
                                По ним начисление идёт по состоянию «не погашено». Если оплата была — руководитель проставит дату в очереди разметки, и вычет пересчитается.
                            </Alert>
                        )}

                        {partners.length === 0 ? (
                            <Alert status="success" title="Просроченных долгов нет">
                                Показатель К1 за этот период не начисляется.
                            </Alert>
                        ) : (
                            <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflowX="auto">
                                <Table.Root size="sm">
                                    <Table.Header>
                                        <Table.Row>
                                            <Table.ColumnHeader w="1%" />
                                            <Table.ColumnHeader>Партнёр</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="right">Должен</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="right">Самая старая</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="right">В день</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="right">Вычтено за месяц</Table.ColumnHeader>
                                            <Table.ColumnHeader>Ступень</Table.ColumnHeader>
                                            <Table.ColumnHeader>Задача</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="right">Действия</Table.ColumnHeader>
                                        </Table.Row>
                                    </Table.Header>
                                    <Table.Body>
                                        {partners.map((p) => {
                                            const isOpen = open.has(p.id);
                                            const level = LEVEL[p.debt_level];

                                            return [
                                                <Table.Row key={p.id} bg={isOpen ? 'bg.subtle' : undefined}>
                                                    <Table.Cell>
                                                        <Box as="button" type="button" onClick={() => toggle(p.id)} aria-expanded={isOpen} aria-label={`Накладные партнёра ${p.name}`} color="fg.subtle" cursor="pointer">
                                                            {isOpen ? <LuChevronDown size={16} /> : <LuChevronRight size={16} />}
                                                        </Box>
                                                    </Table.Cell>
                                                    <Table.Cell>
                                                        <Link href={`/crm/partners/${p.id}`}><Text fontWeight="600" fontSize="sm" _hover={{ textDecoration: 'underline' }}>{p.name}</Text></Link>
                                                        <Text fontSize="xs" color="fg.subtle">
                                                            {p.invoices.length} {plural(p.invoices.length, 'накладная', 'накладные', 'накладных')}
                                                            {p.needs_review_count > 0 ? ` · не разобрано ${p.needs_review_count}` : ''}
                                                        </Text>
                                                    </Table.Cell>
                                                    <Table.Cell textAlign="right"><Text fontSize="sm" fontWeight="700" fontVariantNumeric="tabular-nums">{fmtRub0(p.debt)}</Text></Table.Cell>
                                                    <Table.Cell textAlign="right"><Text fontSize="sm" color={p.oldest_days > 30 ? 'red.fg' : undefined}>{p.oldest_days} {plural(p.oldest_days, 'день', 'дня', 'дней')}</Text></Table.Cell>
                                                    <Table.Cell textAlign="right"><Text fontSize="sm" fontVariantNumeric="tabular-nums" color="orange.fg">−{fmtRub(p.daily_cost, 0)}</Text></Table.Cell>
                                                    <Table.Cell textAlign="right"><Text fontSize="sm" fontWeight="700" fontVariantNumeric="tabular-nums" color="red.fg">−{fmtRub0(p.deducted_this_month)}</Text></Table.Cell>
                                                    <Table.Cell>{level ? <Badge size="xs" colorPalette={level.palette} variant="subtle">{level.label}</Badge> : <Text fontSize="xs" color="fg.subtle">—</Text>}</Table.Cell>
                                                    <Table.Cell><Text fontSize="xs" color={p.next_task_due ? 'fg' : 'fg.subtle'}>{p.next_task_due ? `до ${fmtDay(p.next_task_due)}` : 'нет'}</Text></Table.Cell>
                                                    <Table.Cell textAlign="right">
                                                        <RowActions
                                                            size="xs"
                                                            view={{ href: `/crm/partners/${p.id}`, label: 'Открыть карточку партнёра' }}
                                                            extra={[{ key: 'task', icon: LuListChecks, label: 'Задача на сбор долга', permission: 'crm-tasks.create', onClick: () => setTaskFor(p) }]}
                                                        />
                                                    </Table.Cell>
                                                </Table.Row>,
                                                isOpen && (
                                                    <Table.Row key={`${p.id}-invoices`}>
                                                        <Table.Cell colSpan={9} p={0} bg="bg.subtle">
                                                            <Invoices invoices={p.invoices} />
                                                        </Table.Cell>
                                                    </Table.Row>
                                                ),
                                            ];
                                        })}
                                    </Table.Body>
                                </Table.Root>
                            </Box>
                        )}

                        {excluded.length > 0 && (
                            <Box>
                                <HStack gap={2} mb={2}>
                                    <Text fontWeight="700">Выведено из расчёта</Text>
                                    <MetricHint text="Долги, по которым руководитель прекратил начисление: списанные, переданные в претензионную работу, оспариваемые. Долг виден, вычета по нему нет — это не ошибка." />
                                </HStack>
                                <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflowX="auto">
                                    <Table.Root size="sm">
                                        <Table.Header>
                                            <Table.Row>
                                                <Table.ColumnHeader>Накладная</Table.ColumnHeader>
                                                <Table.ColumnHeader>Партнёр</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="right">Долг</Table.ColumnHeader>
                                                <Table.ColumnHeader>Основание</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="right">Дней без начисления</Table.ColumnHeader>
                                            </Table.Row>
                                        </Table.Header>
                                        <Table.Body>
                                            {excluded.map((row) => (
                                                <Table.Row key={row.invoice_id}>
                                                    <Table.Cell><Text fontSize="sm">{row.number}</Text><Text fontSize="xs" color="fg.subtle">срок {row.due_on ? fmtDay(row.due_on) : '—'}</Text></Table.Cell>
                                                    <Table.Cell><Text fontSize="sm">{row.partner_name}</Text></Table.Cell>
                                                    <Table.Cell textAlign="right"><Text fontSize="sm" fontVariantNumeric="tabular-nums">{fmtRub0(row.balance || row.amount)}</Text></Table.Cell>
                                                    <Table.Cell><Badge size="xs" variant="subtle" colorPalette="gray">{row.reason_label}</Badge></Table.Cell>
                                                    <Table.Cell textAlign="right"><Text fontSize="sm">{row.excluded_days}{row.counted_days > 0 ? ` (начислено ${row.counted_days})` : ''}</Text></Table.Cell>
                                                </Table.Row>
                                            ))}
                                        </Table.Body>
                                    </Table.Root>
                                </Box>
                            </Box>
                        )}
                    </>
                )}
            </VStack>

            <TaskDialog
                open={taskFor !== null}
                entity={taskFor ? { type: 'client', id: taskFor.id } : null}
                initialTitle={taskFor ? `Собрать долг ${fmtRub0(taskFor.debt)} — ${taskFor.name}` : ''}
                onClose={() => setTaskFor(null)}
                onSaved={() => setTaskFor(null)}
            />
        </CrmLayout>
    );
}

function Stat({ label, value, tone, hint }) {
    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
            <HStack gap={1} fontSize="xs" color="fg.muted">
                <Text>{label}</Text>
                {hint && <MetricHint text={hint} />}
            </HStack>
            <Text fontSize="2xl" fontWeight="800" fontVariantNumeric="tabular-nums" color={tone ? `${tone}.fg` : undefined}>{value}</Text>
        </Box>
    );
}

function Invoices({ invoices }) {
    return (
        <Table.Root size="sm" variant="line">
            <Table.Header>
                <Table.Row>
                    <Table.ColumnHeader pl={12}>Накладная</Table.ColumnHeader>
                    <Table.ColumnHeader textAlign="right">Долг</Table.ColumnHeader>
                    <Table.ColumnHeader>Заплатить был должен</Table.ColumnHeader>
                    <Table.ColumnHeader textAlign="right">Опаздывает</Table.ColumnHeader>
                    <Table.ColumnHeader textAlign="right">В день</Table.ColumnHeader>
                    <Table.ColumnHeader textAlign="right">Вычтено за месяц</Table.ColumnHeader>
                    <Table.ColumnHeader>Состояние</Table.ColumnHeader>
                </Table.Row>
            </Table.Header>
            <Table.Body>
                {invoices.map((inv) => (
                    <Table.Row key={inv.id}>
                        <Table.Cell pl={12}>
                            <Text fontSize="sm">{inv.number}</Text>
                            <Text fontSize="xs" color="fg.subtle">{inv.date ? fmtDay(inv.date) : ''} · {fmtRub0(inv.amount)}</Text>
                        </Table.Cell>
                        <Table.Cell textAlign="right"><Text fontSize="sm" fontVariantNumeric="tabular-nums">{fmtRub0(inv.balance)}</Text></Table.Cell>
                        <Table.Cell>
                            <Text fontSize="sm">{inv.grace_ends_on ? fmtDay(inv.grace_ends_on) : '—'}</Text>
                            <Text fontSize="xs" color="fg.subtle">срок {inv.due_on ? fmtDay(inv.due_on) : '—'} + льгота</Text>
                        </Table.Cell>
                        <Table.Cell textAlign="right"><Text fontSize="sm">{inv.overdue_days} {plural(inv.overdue_days, 'день', 'дня', 'дней')}</Text></Table.Cell>
                        <Table.Cell textAlign="right"><Text fontSize="sm" color="orange.fg" fontVariantNumeric="tabular-nums">{inv.balance > 0 ? `−${fmtRub(inv.daily_cost, 0)}` : '—'}</Text></Table.Cell>
                        <Table.Cell textAlign="right"><Text fontSize="sm" color="red.fg" fontVariantNumeric="tabular-nums">−{fmtRub0(inv.deducted_this_month)}</Text></Table.Cell>
                        <Table.Cell>
                            {inv.settled_on
                                ? <Badge size="xs" colorPalette="green" variant="subtle">оплачена {fmtDay(inv.settled_on)}</Badge>
                                : inv.needs_review
                                    ? <Badge size="xs" colorPalette="orange" variant="subtle">дата не восстановлена</Badge>
                                    : <Badge size="xs" colorPalette="gray" variant="subtle">не оплачена</Badge>}
                        </Table.Cell>
                    </Table.Row>
                ))}
            </Table.Body>
        </Table.Root>
    );
}
