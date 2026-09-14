import { useEffect, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { Badge, Box, HStack, SimpleGrid, Table, Text, VStack } from '@chakra-ui/react';
import { LuCalculator, LuCheck, LuLock, LuPencil } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import MetricHint from '@/Crm/Components/MetricHint';
import { toastError, toastSuccess } from '@/utils/toast';
import { fmtDay, fmtFactor, fmtPercent, fmtRub0 } from '../Salary/components/format';
import MotivationTabs from './components/MotivationTabs';
import { hubBreadcrumbs } from './components/hubs';

const selectStyle = {
    padding: '0.45rem 0.6rem',
    borderRadius: '0.5rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '180px',
    background: 'var(--chakra-colors-bg-panel)',
    color: 'var(--chakra-colors-fg)',
};

const inputStyle = {
    padding: '0.35rem 0.5rem',
    borderRadius: '0.5rem',
    border: '1px solid var(--chakra-colors-border)',
    background: 'var(--chakra-colors-bg-panel)',
    color: 'var(--chakra-colors-fg)',
    width: '140px',
    textAlign: 'right',
    fontVariantNumeric: 'tabular-nums',
};

const MONTHS = ['январь', 'февраль', 'март', 'апрель', 'май', 'июнь', 'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь'];
const monthName = (iso) => MONTHS[Number(String(iso).split('-')[1]) - 1] ?? iso;

/**
 * Мастер планов на квартал: расчёт по формуле, сравнение с действующим планом,
 * вето на повышение по результату, ограничитель снижения, утверждение приказа.
 *
 * Расхождение методик достигает двукратного, и руководитель обязан увидеть его
 * до утверждения, а не после — блок сравнения обязателен.
 */
export default function MotivationPlans(props) {
    const [data, setData] = useState(props);
    const [busy, setBusy] = useState(false);

    useEffect(() => setData(props), [props]);

    const changeQuarter = (quarter) => router.get('/crm/motivation/plans', { quarter }, { preserveState: true, preserveScroll: true, replace: true });

    const call = async (url, payload, okMessage) => {
        setBusy(true);
        try {
            const res = await axios.post(url, { quarter: data.quarter.slice(0, 7), ...payload });
            setData(res.data);
            if (okMessage || res.data.message) toastSuccess(res.data.message ?? okMessage);
        } catch (e) {
            toastError(e.response?.data?.message ?? Object.values(e.response?.data?.errors ?? {}).flat().join(' ') ?? 'Не удалось выполнить');
        } finally {
            setBusy(false);
        }
    };

    const delta = data.comparison.delta_percent;
    const allApproved = data.managers.length > 0 && data.managers.every((m) => m.order?.approved);
    const hasDrafts = data.managers.some((m) => m.order && !m.order.approved);

    return (
        <CrmLayout breadcrumbs={hubBreadcrumbs('rules', 'plans')}>
            <Head title="Планы на квартал — CRM" />
            <PageHeader
                title="Планы на квартал"
                description="Расчёт по формуле Положения, сравнение с действующим планом и утверждение приказа за десять рабочих дней до начала квартала."
                actions={(
                    <HStack gap={2} flexWrap="wrap">
                        <select aria-label="Квартал" style={selectStyle} value={data.quarter.slice(0, 7)} onChange={(e) => changeQuarter(e.target.value)}>
                            {data.quarters.map((q) => <option key={q.value} value={q.value}>{q.label}</option>)}
                        </select>
                        {data.can_edit && !allApproved && (
                            <Button size="sm" onClick={() => call('/crm/motivation/plans/calculate', {}, 'Черновики приказов посчитаны')} loading={busy}>
                                <LuCalculator /> {hasDrafts ? 'Пересчитать черновики' : 'Рассчитать планы'}
                            </Button>
                        )}
                    </HStack>
                )}
            />
            <MotivationTabs hub="rules" current="plans" />

            <VStack align="stretch" gap={4}>
                {data.timesheet_empty && (
                    <Alert status="warning" title="Табель за период выборки пуст">
                        Медиана посчитана без исключения отсутствий. Если отпуска были, а в табеле их нет, план занижен — заполните табель и пересчитайте.
                    </Alert>
                )}

                <SimpleGrid columns={{ base: 1, sm: 3 }} gap={3}>
                    <Stat label={`${data.quarter_label} · по методике`} value={fmtRub0(data.comparison.calculated_total)} hint="Сумма планов всех работников за три месяца квартала по расчёту или по утверждённым приказам." />
                    <Stat label="Действующие планы" value={data.comparison.current_total > 0 ? fmtRub0(data.comparison.current_total) : '—'} hint="То, что стоит в «Планах продаж» сейчас — поставлено по прежней методике, сверху вниз от цифры компании." />
                    <Stat label="Разница" value={delta === null ? '—' : fmtPercent(delta, 0)} tone={delta === null ? undefined : delta < 0 ? 'red' : 'green'} hint="Расхождение методик достигает двукратного. Оно должно быть видно до утверждения, а не после." />
                </SimpleGrid>

                {data.managers.length === 0 && (
                    <Alert status="info" title="Нет работников с расчётом оплаты труда">Включите расчёт работникам в настройках зарплаты.</Alert>
                )}

                {data.managers.map((m) => (
                    <ManagerCard key={m.id} manager={m} data={data} busy={busy} call={call} />
                ))}
            </VStack>
        </CrmLayout>
    );
}

function ManagerCard({ manager: m, data, busy, call }) {
    const order = m.order;
    const approved = Boolean(order?.approved);
    const [values, setValues] = useState(() => Object.fromEntries(m.months.map((x) => [x.month, x.value])));
    const [comment, setComment] = useState(order?.comment ?? '');
    const [waive, setWaive] = useState(order?.waived_reason ?? '');
    const [editing, setEditing] = useState(false);

    useEffect(() => {
        setValues(Object.fromEntries(m.months.map((x) => [x.month, x.value])));
        setComment(order?.comment ?? '');
        setEditing(false);
    }, [m, order]);

    const edited = m.months.some((x) => Number(values[x.month]) !== Number(x.value));
    const sampleMonths = m.sample_months.filter((r) => r.per_day !== null);
    const medianHint = `Обычные продажи за рабочий день, приведённые к сезону 1,0: медиана ${sampleMonths.length} месяцев (${fmtDay(m.sample_from)} — ${fmtDay(m.sample_to)}, ${m.days_counted} раб. дн., исключено по табелю ${m.days_excluded_absence}). По месяцам: ${sampleMonths.map((r) => `${monthName(r.month).slice(0, 3)} ${fmtRub0(r.per_day_adjusted)}${r.seasonal !== 1 ? ` (${fmtRub0(r.per_day)} ÷ ${fmtFactor(r.seasonal)})` : ''}`).join(' · ')}. Новые партнёры в выборку не входят (п. 6.3.3).`;
    const calculatedHint = `Медиана в день × рабочие дни × сезон × (1 + рост)${m.overperformance_carry > 0 ? ` + треть от половины перевыполнения прошлого квартала ${fmtRub0(m.overperformance_carry)} (п. 5.4)` : ''}. Предел снижения ${m.decline_limited ? 'применён: расчёт был ниже плана прошлого квартала более чем на допустимую долю' : (m.previous_quarter_comparable ? 'не потребовался' : 'не применяется — прошлый квартал поставлен по другой методике')}.`;
    const canType = data.can_edit && (!approved || editing);
    const save = (approve) => call(
        '/crm/motivation/plans/save',
        { manager: m.id, values, comment, approve },
    );

    return (
        <Box bg="bg.panel" borderWidth="2px" borderColor={approved ? 'green.solid' : 'border'} borderRadius="xl" p={{ base: 4, md: 5 }}>
            <HStack justify="space-between" flexWrap="wrap" gap={2} mb={3}>
                <HStack gap={2}>
                    <Text fontWeight="800" fontSize="lg">{m.name}</Text>
                    {approved && <Badge colorPalette="green" variant="subtle"><LuLock size={11} /> утверждён {fmtDay(order.approved_at)} · версия {order.version}</Badge>}
                    {order && !approved && <Badge colorPalette="blue" variant="subtle">черновик · версия {order.version}</Badge>}
                    {!order && <Badge colorPalette="gray" variant="subtle">не утверждён</Badge>}
                </HStack>
                <Text fontSize="sm" color="fg.muted">
                    Итого за квартал <Text as="span" fontWeight="700">{fmtRub0(m.total)}</Text>{m.current_total > 0 ? ` против действующих ${fmtRub0(m.current_total)}` : ''}
                </Text>
            </HStack>

            {m.warnings.map((w) => <Text key={w} fontSize="xs" color="orange.fg" mb={1}>{w}</Text>)}

            <Table.Root size="sm" mt={2}>
                <Table.Header>
                    <Table.Row>
                        <Table.ColumnHeader>Месяц</Table.ColumnHeader>
                        <Table.ColumnHeader textAlign="right"><Th label="Медиана в день" hint={medianHint} /></Table.ColumnHeader>
                        <Table.ColumnHeader textAlign="right"><Th label="Раб. дней" hint="По производственному календарю. Отпуск и больничный уменьшают план месяца пропорционально отработанным дням (п. 10.2)." /></Table.ColumnHeader>
                        <Table.ColumnHeader textAlign="right"><Th label="Сезон" hint="Насколько месяц сильнее или слабее среднего месяца года по продажам 2023–2025. Единый для отдела, задаётся приказом в «Параметрах»." /></Table.ColumnHeader>
                        <Table.ColumnHeader textAlign="right"><Th label="Рост" hint="Целевой прирост на год, единый для отдела (п. 5.2). Единственный множитель, где компания говорит «хотим больше, чем было»." /></Table.ColumnHeader>
                        <Table.ColumnHeader textAlign="right"><Th label="Расчётный" hint={calculatedHint} /></Table.ColumnHeader>
                        <Table.ColumnHeader textAlign="right"><Th label={approved && !editing ? 'Утверждённый' : 'План'} hint="Что утверждено приказом или будет утверждено. Отличие от расчётного — правка руководителя с комментарием; повышение со ссылкой на перевыполнение блокируется (п. 5.4)." /></Table.ColumnHeader>
                        <Table.ColumnHeader textAlign="right"><Th label="Действующий" hint="Что стоит в «Планах продаж» сейчас. До первого приказа — план по прежней методике, сверху вниз от цифры компании." /></Table.ColumnHeader>
                        <Table.ColumnHeader textAlign="right"><Th label="Разница" hint="План к действующему." /></Table.ColumnHeader>
                    </Table.Row>
                </Table.Header>
                <Table.Body>
                    {m.months.map((x) => {
                        const v = Number(values[x.month] ?? x.value);
                        const diff = x.current_plan ? v / x.current_plan - 1 : null;
                        return (
                            <Table.Row key={x.month}>
                                <Table.Cell><Text fontSize="sm" textTransform="capitalize">{monthName(x.month)}</Text></Table.Cell>
                                <Table.Cell textAlign="right"><Text fontSize="sm" fontVariantNumeric="tabular-nums">{fmtRub0(m.median_per_day)}</Text></Table.Cell>
                                <Table.Cell textAlign="right"><Text fontSize="sm">{x.working_days}</Text></Table.Cell>
                                <Table.Cell textAlign="right"><Text fontSize="sm">{fmtFactor(x.seasonal)}</Text></Table.Cell>
                                <Table.Cell textAlign="right"><Text fontSize="sm">{fmtPercent(m.growth_rate, 0)}</Text></Table.Cell>
                                <Table.Cell textAlign="right"><Text fontSize="sm" color="fg.muted" fontVariantNumeric="tabular-nums">{fmtRub0(x.calculated)}</Text></Table.Cell>
                                <Table.Cell textAlign="right">
                                    {!canType
                                        ? <Text fontSize="sm" fontWeight="700" fontVariantNumeric="tabular-nums">{fmtRub0(v)}</Text>
                                        : <input type="number" step="1000" style={inputStyle} value={v} aria-label={`План на ${monthName(x.month)}`} onChange={(e) => setValues({ ...values, [x.month]: e.target.value === '' ? 0 : Number(e.target.value) })} />}
                                </Table.Cell>
                                <Table.Cell textAlign="right"><Text fontSize="sm" color="fg.muted" fontVariantNumeric="tabular-nums">{x.current_plan === null ? '—' : fmtRub0(x.current_plan)}</Text></Table.Cell>
                                <Table.Cell textAlign="right"><Text fontSize="sm" color={diff === null ? 'fg.subtle' : diff < 0 ? 'red.fg' : 'green.fg'}>{diff === null ? '—' : fmtPercent(diff, 0)}</Text></Table.Cell>
                            </Table.Row>
                        );
                    })}
                </Table.Body>
            </Table.Root>

            {approved && data.can_edit && !editing && (
                <HStack justify="flex-end" mt={3}>
                    <Button size="sm" variant="outline" onClick={() => setEditing(true)}>
                        <LuPencil /> Изменить план
                    </Button>
                </HStack>
            )}

            {canType && (
                <VStack align="stretch" gap={2} mt={3}>
                    <HStack gap={2} flexWrap="wrap" align="end">
                        <Box flex="1" minW="260px">
                            <Text fontSize="xs" color="fg.muted" mb={1}>Комментарий (необязательно)</Text>
                            <input style={{ ...inputStyle, width: '100%', textAlign: 'left' }} value={comment} aria-label="Комментарий к плану" onChange={(e) => setComment(e.target.value)} placeholder="Например: переданы партнёры из пула" />
                        </Box>
                        {editing && (
                            <Button size="sm" variant="ghost" disabled={busy} onClick={() => { setEditing(false); setValues(Object.fromEntries(m.months.map((x) => [x.month, x.value]))); }}>
                                Отмена
                            </Button>
                        )}
                        {!editing && (
                            <Button size="sm" variant="outline" loading={busy} disabled={!edited && Boolean(order)} onClick={() => save(false)}>
                                Сохранить черновик
                            </Button>
                        )}
                        <Button size="sm" colorPalette="green" loading={busy} onClick={() => save(true)}>
                            <LuCheck /> Утвердить план
                        </Button>
                    </HStack>
                    <Text fontSize="xs" color="fg.subtle">
                        Утверждённый план записывается в «Планы продаж». Повышение против расчётного с основанием «перевыполнил в прошлом периоде» блокируется — половина перевыполнения уже учтена (п. 5.4).
                    </Text>
                </VStack>
            )}

            {order && !approved && data.can_edit && (
                <VStack align="stretch" gap={2} mt={3}>
                    {(m.decline_limited || m.previous_quarter_comparable) && (
                        <HStack gap={2} flexWrap="wrap" align="end">
                            <Box flex="1" minW="260px">
                                <HStack gap={1} mb={1}>
                                    <Text fontSize="xs" color="fg.muted">Снять предел снижения — основание</Text>
                                    <MetricHint text="Предел снимается только при документально оформленной передаче партнёров или прекращении деятельности партнёра: снижение вызвано изменением состава базы, а не результатом работы." />
                                </HStack>
                                <input style={{ ...inputStyle, width: '100%', textAlign: 'left' }} value={waive} aria-label="Основание снятия предела" onChange={(e) => setWaive(e.target.value)} placeholder="Переданы партнёры … по приказу № …" />
                            </Box>
                            <Button size="sm" variant="outline" loading={busy} disabled={waive.trim().length < 5} onClick={() => call('/crm/motivation/plans/calculate', { manager: m.id, waive_reason: waive }, 'Пересчитано без предела снижения')}>
                                Пересчитать без предела
                            </Button>
                        </HStack>
                    )}
                </VStack>
            )}

            {approved && order.comment && <Text fontSize="sm" color="fg.muted" mt={2}>Обоснование: {order.comment}</Text>}
            {approved && order.waived_reason && <Text fontSize="sm" color="fg.muted">Предел снижения снят: {order.waived_reason}</Text>}
        </Box>
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

function Th({ label, hint }) {
    return (
        <HStack gap={1} justify="end" display="inline-flex">
            <Text>{label}</Text>
            <MetricHint text={hint} />
        </HStack>
    );
}
