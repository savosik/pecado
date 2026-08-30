import { useEffect, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { Badge, Box, HStack, SimpleGrid, Table, Text, VStack } from '@chakra-ui/react';
import { LuBanknote, LuCheck, LuFileDown, LuRefreshCw, LuUndo2 } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/Admin/Components/ConfirmDialog';
import RowActions from '@/shared/Panel/RowActions';
import { useConfirmDelete } from '@/shared/Panel/useConfirmDelete';
import { toastError, toastSuccess } from '@/utils/toast';
import { fmtCompact, fmtFactor, fmtPercent, fmtRub0 } from './components/format';

const selectStyle = {
    padding: '0.45rem 0.6rem',
    borderRadius: '0.5rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '180px',
    background: 'var(--chakra-colors-bg-panel)',
    color: 'var(--chakra-colors-fg)',
};

const STATUS_PALETTE = { draft: 'blue', approved: 'green', paid: 'gray' };

function Tile({ label, value, note, tone }) {
    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
            <Text fontSize="xs" color="fg.muted">{label}</Text>
            <Text fontSize="2xl" fontWeight="700" fontVariantNumeric="tabular-nums" color={tone === 'red' ? 'red.fg' : undefined}>{value}</Text>
            {note && <Text fontSize="xs" color="fg.muted" mt={1}>{note}</Text>}
        </Box>
    );
}

/**
 * Зарплата отдела (РОП): все менеджеры за месяц, утверждение и выплата.
 *
 * Черновик утверждается после пересчёта на сервере — РОП замораживает то, что
 * видит. Утверждённый снимок не меняется от новых данных; «переоткрыть» —
 * новая версия рядом со старой.
 */
export default function SalaryTeam(props) {
    const [data, setData] = useState(props);
    const [pending, setPending] = useState(null);

    useEffect(() => setData(props), [props]);

    const changeMonth = (month) => router.get('/crm/salary/team', { month }, { preserveState: true, preserveScroll: true });

    const reload = async () => {
        const res = await axios.get('/crm/salary/team/data', { params: { month: data.month } });
        setData(res.data);
    };

    const act = async (row, action) => {
        try {
            await axios.post(`/crm/salary/calculations/${row.calculation.id}/${action}`);
            toastSuccess({
                recalculate: 'Черновик пересчитан',
                approve: 'Расчёт утверждён',
                paid: 'Отмечено как выплаченное',
                reopen: 'Расчёт переоткрыт — новая версия черновика',
            }[action]);
            await reload();
        } catch (e) {
            toastError(e.response?.data?.message ?? 'Не удалось выполнить действие');
        }
    };

    const confirm = useConfirmDelete({
        title: 'Подтвердите действие',
        confirmLabel: 'Да',
        colorPalette: 'blue',
        description: (target) => target?.description ?? '',
        onConfirm: (target) => act(target.row, target.action),
    });

    const ask = (row, action, description) => confirm.request({ row, action, description });

    const totals = data.totals ?? {};
    const rows = data.rows ?? [];

    return (
        <CrmLayout breadcrumbs={[{ label: 'Команда' }, { label: 'Зарплата отдела' }]}>
            <Head title="Зарплата отдела — CRM" />
            <PageHeader
                title="Зарплата отдела"
                description="Сводка по менеджерам за месяц: черновики, утверждение и выплата."
                actions={(
                    <HStack gap={2} flexWrap="wrap">
                        <select aria-label="Месяц" style={selectStyle} value={data.month} onChange={(e) => changeMonth(e.target.value)}>
                            {(data.months ?? []).map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
                        </select>
                        <Button size="sm" variant="outline" asChild>
                            <a href={`/crm/salary/team/export?month=${data.month}`}><LuFileDown /> XLSX</a>
                        </Button>
                    </HStack>
                )}
            />

            <VStack align="stretch" gap={5}>
                <SimpleGrid columns={{ base: 2, md: 4 }} gap={3}>
                    <Tile label={`ФОТ отдела · ${data.month_label}`} value={fmtRub0(totals.total)} note={`${rows.length} менедж. · черновиков ${data.statuses?.draft ?? 0}, утверждено ${data.statuses?.approved ?? 0}, выплачено ${data.statuses?.paid ?? 0}`} />
                    <Tile label="KPI-премии" value={fmtRub0(totals.kpi_bonus)} note={`оклады ${fmtCompact(totals.salary)} · доп. ${fmtCompact(totals.extra_income)}`} />
                    <Tile label="Штрафы за дисциплину" value={fmtRub0(totals.penalty)} tone={totals.penalty > 0 ? 'red' : undefined} note="вычтено из выручки до сравнения с планом" />
                    <Tile label="Выручка отдела" value={fmtCompact(totals.revenue)} note={totals.plan > 0 ? `план ${fmtCompact(totals.plan)} · ${fmtPercent(totals.revenue / totals.plan, 0)}` : 'план не задан'} />
                </SimpleGrid>

                <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflowX="auto">
                    <Table.Root size="sm" variant="line">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeader>Менеджер</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">Оклад</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">KPI</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">Штраф</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">Доп. / корр.</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">Итого</Table.ColumnHeader>
                                <Table.ColumnHeader>План / факт</Table.ColumnHeader>
                                <Table.ColumnHeader>Активные</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">Прогноз</Table.ColumnHeader>
                                <Table.ColumnHeader>Статус</Table.ColumnHeader>
                                <Table.ColumnHeader w="160px" />
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {rows.map((row) => {
                                const c = row.calculation;
                                const extras = [];
                                if (data.can_edit) {
                                    if (c.status === 'draft') {
                                        extras.push({ key: 'recalc', icon: LuRefreshCw, label: 'Пересчитать сейчас', onClick: () => act(row, 'recalculate') });
                                        extras.push({ key: 'approve', icon: LuCheck, label: 'Утвердить', colorPalette: 'green', onClick: () => ask(row, 'approve', `${row.manager.name}: ${fmtRub0(c.total)} за ${data.month_label.toLowerCase()} будет заморожено. Поздние оплаты и отгрузки на утверждённый расчёт не повлияют.`) });
                                    }
                                    if (c.status === 'approved') {
                                        extras.push({ key: 'paid', icon: LuBanknote, label: 'Отметить выплаченным', onClick: () => ask(row, 'paid', `${row.manager.name}: ${fmtRub0(c.total)} — отметить как выплаченное?`) });
                                    }
                                    if (c.is_frozen) {
                                        extras.push({ key: 'reopen', icon: LuUndo2, label: 'Переоткрыть', onClick: () => ask(row, 'reopen', `${row.manager.name}: появится новая версия черновика, утверждённая версия ${c.version} останется в истории.`) });
                                    }
                                }

                                return (
                                    <Table.Row key={row.manager.id}>
                                        <Table.Cell>
                                            <Text fontWeight="600">{row.manager.name}</Text>
                                            {row.warnings?.length > 0 && <Text fontSize="xs" color="orange.fg" lineClamp={1}>{row.warnings[0]}</Text>}
                                        </Table.Cell>
                                        <Table.Cell textAlign="right" fontVariantNumeric="tabular-nums">{fmtRub0(row.amounts.salary)}</Table.Cell>
                                        <Table.Cell textAlign="right" fontVariantNumeric="tabular-nums" whiteSpace="nowrap">
                                            {fmtRub0(row.amounts.kpi_bonus)}
                                            <Text as="span" fontSize="xs" color="fg.muted"> · {fmtPercent(row.kpi.performance, 0)} × {fmtFactor(row.kpi.multiplier)}</Text>
                                        </Table.Cell>
                                        <Table.Cell textAlign="right" fontVariantNumeric="tabular-nums" color={row.kpi.penalty > 0 ? 'red.fg' : 'fg.muted'}>
                                            {row.kpi.penalty > 0 ? fmtCompact(row.kpi.penalty) : '—'}
                                        </Table.Cell>
                                        <Table.Cell textAlign="right" fontVariantNumeric="tabular-nums" whiteSpace="nowrap">
                                            {fmtRub0((row.amounts.extra_income ?? 0) + (row.amounts.new_clients_bonus ?? 0) + (row.amounts.manual_correction ?? 0))}
                                        </Table.Cell>
                                        <Table.Cell textAlign="right" fontWeight="700" fontVariantNumeric="tabular-nums">{fmtRub0(c.total)}</Table.Cell>
                                        <Table.Cell whiteSpace="nowrap">
                                            {fmtCompact(row.inputs.revenue)}
                                            <Text as="span" fontSize="xs" color="fg.muted"> {row.inputs.plan ? `из ${fmtCompact(row.inputs.plan)} · ${fmtPercent(row.inputs.percent, 0)}` : '· без плана'}</Text>
                                        </Table.Cell>
                                        <Table.Cell whiteSpace="nowrap">{row.inputs.active_count} из {row.inputs.planned_count}</Table.Cell>
                                        <Table.Cell textAlign="right" fontVariantNumeric="tabular-nums" color="fg.muted">{row.forecast_total ? fmtRub0(row.forecast_total) : '—'}</Table.Cell>
                                        <Table.Cell>
                                            <Badge size="sm" variant="subtle" colorPalette={STATUS_PALETTE[c.status] ?? 'gray'}>
                                                {c.status_label}{c.version > 1 ? ` · v${c.version}` : ''}
                                            </Badge>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <RowActions
                                                view={{ href: `/crm/salary?manager=${row.manager.id}&month=${data.month}`, label: 'Открыть расчёт' }}
                                                extra={extras}
                                            />
                                        </Table.Cell>
                                    </Table.Row>
                                );
                            })}
                        </Table.Body>
                    </Table.Root>
                </Box>
            </VStack>

            <ConfirmDialog {...confirm.dialogProps} loading={Boolean(pending)} />
        </CrmLayout>
    );
}
