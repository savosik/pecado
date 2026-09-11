import { useEffect, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { Badge, Box, HStack, SimpleGrid, Table, Text, VStack } from '@chakra-ui/react';
import { LuCalculator, LuCheck, LuLock } from 'react-icons/lu';
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

    useEffect(() => {
        setValues(Object.fromEntries(m.months.map((x) => [x.month, x.value])));
        setComment(order?.comment ?? '');
    }, [m, order]);

    const edited = m.months.some((x) => Number(values[x.month]) !== Number(x.value));

    return (
        <Box bg="bg.panel" borderWidth="2px" borderColor={approved ? 'green.solid' : 'border'} borderRadius="xl" p={{ base: 4, md: 5 }}>
            <HStack justify="space-between" flexWrap="wrap" gap={2} mb={3}>
                <HStack gap={2}>
                    <Text fontWeight="800" fontSize="lg">{m.name}</Text>
                    {approved && <Badge colorPalette="green" variant="subtle"><LuLock size={11} /> утверждён {fmtDay(order.approved_at)} · версия {order.version}</Badge>}
                    {order && !approved && <Badge colorPalette="blue" variant="subtle">черновик приказа · версия {order.version}</Badge>}
                    {!order && <Badge colorPalette="gray" variant="subtle">расчёт без приказа</Badge>}
                </HStack>
                <Text fontSize="sm" color="fg.muted">
                    Итого за квартал <Text as="span" fontWeight="700">{fmtRub0(m.total)}</Text>{m.current_total > 0 ? ` против действующих ${fmtRub0(m.current_total)}` : ''}
                </Text>
            </HStack>

            <SimpleGrid columns={{ base: 2, md: 4 }} gap={3} mb={3}>
                <Step label="Медиана за рабочий день" value={fmtRub0(m.median_per_day)} note={`${m.days_counted} дн. выборки, ${fmtDay(m.sample_from)} — ${fmtDay(m.sample_to)}; исключено по табелю ${m.days_excluded_absence}`} />
                <Step label="Половина перевыполнения" value={m.overperformance_carry > 0 ? fmtRub0(m.overperformance_carry) : '—'} note="п. 5.4, распределяется на три месяца" />
                <Step label="Целевой прирост" value={fmtPercent(m.growth_rate, 1)} note="приказом на год" />
                <Step
                    label="Предел снижения"
                    value={m.decline_limited ? 'применён' : (m.previous_quarter_comparable ? 'не потребовался' : 'не применяется')}
                    note={m.previous_quarter_total !== null
                        ? (m.previous_quarter_comparable ? `план прошлого квартала ${fmtRub0(m.previous_quarter_total)}` : 'прошлый квартал поставлен по другой методике')
                        : 'прошлого квартала нет'}
                />
            </SimpleGrid>

            {m.warnings.map((w) => <Text key={w} fontSize="xs" color="orange.fg" mb={1}>{w}</Text>)}

            <Table.Root size="sm" mt={2}>
                <Table.Header>
                    <Table.Row>
                        <Table.ColumnHeader>Месяц</Table.ColumnHeader>
                        <Table.ColumnHeader textAlign="right">Раб. дней</Table.ColumnHeader>
                        <Table.ColumnHeader textAlign="right">Сезон</Table.ColumnHeader>
                        <Table.ColumnHeader textAlign="right">Расчётный</Table.ColumnHeader>
                        <Table.ColumnHeader textAlign="right">{approved ? 'Утверждённый' : 'К утверждению'}</Table.ColumnHeader>
                        <Table.ColumnHeader textAlign="right">Действующий</Table.ColumnHeader>
                        <Table.ColumnHeader textAlign="right">Разница</Table.ColumnHeader>
                    </Table.Row>
                </Table.Header>
                <Table.Body>
                    {m.months.map((x) => {
                        const v = Number(values[x.month] ?? x.value);
                        const diff = x.current_plan ? v / x.current_plan - 1 : null;
                        return (
                            <Table.Row key={x.month}>
                                <Table.Cell><Text fontSize="sm" textTransform="capitalize">{monthName(x.month)}</Text></Table.Cell>
                                <Table.Cell textAlign="right"><Text fontSize="sm">{x.working_days}</Text></Table.Cell>
                                <Table.Cell textAlign="right"><Text fontSize="sm">{fmtFactor(x.seasonal)}</Text></Table.Cell>
                                <Table.Cell textAlign="right"><Text fontSize="sm" color="fg.muted" fontVariantNumeric="tabular-nums">{fmtRub0(x.calculated)}</Text></Table.Cell>
                                <Table.Cell textAlign="right">
                                    {approved || !order || !data.can_edit
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

            {order && !approved && data.can_edit && (
                <VStack align="stretch" gap={2} mt={3}>
                    {(edited || order.manual) && (
                        <HStack gap={2} flexWrap="wrap" align="end">
                            <Box flex="1" minW="260px">
                                <Text fontSize="xs" color="fg.muted" mb={1}>Обоснование отклонения от расчётного значения</Text>
                                <input style={{ ...inputStyle, width: '100%', textAlign: 'left' }} value={comment} aria-label="Обоснование" onChange={(e) => setComment(e.target.value)} placeholder="Например: переданы партнёры из пула" />
                            </Box>
                            <Button size="sm" variant="outline" loading={busy} disabled={!edited || comment.trim().length < 5} onClick={() => call(`/crm/motivation/plans/${order.id}/override`, { values, comment }, 'Значения черновика сохранены')}>
                                Сохранить правку
                            </Button>
                        </HStack>
                    )}
                    <Text fontSize="xs" color="fg.subtle">
                        Повышение против расчётного по основанию «перевыполнил в прошлом месяце» блокируется — половина перевыполнения уже учтена (п. 5.4).
                    </Text>
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
                    <HStack justify="flex-end">
                        <Button size="sm" colorPalette="green" loading={busy} disabled={edited} onClick={() => call(`/crm/motivation/plans/${order.id}/approve`, {})}>
                            <LuCheck /> Утвердить приказ
                        </Button>
                    </HStack>
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

function Step({ label, value, note }) {
    return (
        <VStack align="start" gap={0}>
            <Text fontSize="xs" color="fg.muted">{label}</Text>
            <Text fontWeight="700" fontVariantNumeric="tabular-nums">{value}</Text>
            <Text fontSize="xs" color="fg.subtle">{note}</Text>
        </VStack>
    );
}
