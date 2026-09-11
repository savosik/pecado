import { useEffect, useMemo, useRef, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { Badge, Box, HStack, SimpleGrid, Table, Text, VStack } from '@chakra-ui/react';
import { LuHistory, LuLock, LuSave, LuUser } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { SegmentedControl } from '@/components/ui/segmented-control';
import MetricHint from '@/Crm/Components/MetricHint';
import { toastError, toastSuccess } from '@/utils/toast';
import ParamField, { formatValue } from './components/settings/ParamField';
import PersonalOverrides from './components/settings/PersonalOverrides';
import { fmtDay, fmtRub, fmtSigned } from '../Salary/components/format';

const selectStyle = {
    padding: '0.45rem 0.6rem',
    borderRadius: '0.5rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '180px',
    background: 'var(--chakra-colors-bg-panel)',
    color: 'var(--chakra-colors-fg)',
};

const inputStyle = { ...selectStyle, minWidth: '120px' };

/**
 * «Параметры мотивации» — центральный управляющий экран системы.
 *
 * Параметры — приказ, действующий с периода, а не настройка приложения.
 * Экран устроен вокруг набора значений с датой начала действия: правки
 * копятся в черновике, предпросмотр показывает, что станет с доходом каждого
 * работника, и только потом издаётся приказ.
 */
export default function MotivationSettings(props) {
    const [data, setData] = useState(props);
    const [tab, setTab] = useState('order');
    const [draft, setDraft] = useState(props.values);
    const [preview, setPreview] = useState(null);
    const [previewing, setPreviewing] = useState(false);
    const [issuing, setIssuing] = useState(false);
    const [issue, setIssue] = useState({ effective_from: nextMonth(), order_number: '', order_date: '', comment: '' });
    const timer = useRef(null);

    useEffect(() => { setData(props); setDraft(props.values); }, [props]);

    const changed = useMemo(() => Object.fromEntries(
        Object.entries(draft).filter(([k, v]) => JSON.stringify(v) !== JSON.stringify(data.values[k])),
    ), [draft, data.values]);
    const dirty = Object.keys(changed).length > 0;

    useEffect(() => {
        if (!dirty || !data.can_edit) { setPreview(null); return undefined; }
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(async () => {
            setPreviewing(true);
            try {
                const res = await axios.post('/crm/motivation/settings/preview', { month: data.month.slice(0, 7), values: changed });
                setPreview(res.data);
            } catch (e) {
                toastError(e.response?.data?.message ?? 'Предпросмотр не удался');
            } finally {
                setPreviewing(false);
            }
        }, 400);
        return () => window.clearTimeout(timer.current);
    }, [changed, dirty, data.month, data.can_edit]);

    const changeMonth = (month) => router.get('/crm/motivation/settings', { month }, { preserveState: true, preserveScroll: true, replace: true });

    const submitOrder = async () => {
        setIssuing(true);
        try {
            const res = await axios.post('/crm/motivation/settings/order', { ...issue, values: draft });
            toastSuccess(res.data.message);
            (res.data.warnings ?? []).forEach((w) => toastError(w));
            router.reload();
        } catch (e) {
            toastError(e.response?.data?.message ?? 'Приказ не издан');
        } finally {
            setIssuing(false);
        }
    };

    const errors = preview?.errors ?? [];

    return (
        <CrmLayout breadcrumbs={[{ label: 'Мотивация' }, { label: 'Параметры мотивации' }]}>
            <Head title="Параметры мотивации — CRM" />
            <PageHeader
                title="Параметры мотивации"
                description="Все числовые параметры Положения. Изменение — приказом, действующим с периода; утверждённые месяцы читаются по своим значениям."
                actions={(
                    <HStack gap={2} flexWrap="wrap">
                        <select aria-label="Период" style={selectStyle} value={data.month.slice(0, 7)} onChange={(e) => changeMonth(e.target.value)}>
                            {(data.months ?? []).map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
                        </select>
                    </HStack>
                )}
            />

            <VStack align="stretch" gap={4}>
                <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
                    {data.current_order ? (
                        <HStack gap={3} flexWrap="wrap">
                            <Badge colorPalette="green" variant="subtle"><LuLock size={11} /> действует с {fmtDay(data.current_order.effective_from)}</Badge>
                            <Text fontSize="sm">
                                Приказ {data.current_order.order_number ? `№ ${data.current_order.order_number}` : 'без номера'}
                                {data.current_order.order_date ? ` от ${fmtDay(data.current_order.order_date)}` : ''}
                                {data.current_order.author ? ` · ввёл ${data.current_order.author}` : ''}
                            </Text>
                            {data.current_order.comment && <Text fontSize="sm" color="fg.muted">{data.current_order.comment}</Text>}
                        </HStack>
                    ) : (
                        <HStack gap={3} flexWrap="wrap">
                            <Badge colorPalette="orange" variant="subtle">приказ не издан</Badge>
                            <Text fontSize="sm" color="fg.muted">Показаны умолчания Приложения № 1 Положения. Издайте первый приказ, чтобы значения жили в системе, а не в коде.</Text>
                        </HStack>
                    )}
                </Box>

                <SegmentedControl
                    value={tab}
                    onValueChange={(e) => setTab(e.value)}
                    items={[
                        { value: 'order', label: 'Приказ' },
                        { value: 'history', label: `История · ${data.history.length}` },
                        { value: 'personal', label: `По работнику · ${data.personal.length}` },
                    ]}
                />

                {tab === 'order' && (
                    <SimpleGrid columns={{ base: 1, xl: dirty ? 2 : 1 }} gap={4} alignItems="start">
                        <VStack align="stretch" gap={4}>
                            {data.groups.map((group) => (
                                <Box key={group.key} bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={{ base: 4, md: 5 }}>
                                    <Text fontWeight="700" mb={3}>{group.label}</Text>
                                    <VStack align="stretch" gap={3}>
                                        {group.params.map((param) => (
                                            <ParamField
                                                key={param.key}
                                                param={param}
                                                value={draft[param.key]}
                                                original={data.values[param.key]}
                                                disabled={!data.can_edit}
                                                onChange={(value) => setDraft((prev) => ({ ...prev, [param.key]: value }))}
                                            />
                                        ))}
                                    </VStack>
                                </Box>
                            ))}
                        </VStack>

                        {dirty && data.can_edit && (
                            <VStack align="stretch" gap={4} position={{ xl: 'sticky' }} top={4}>
                                <Box bg="bg.panel" borderWidth="2px" borderColor="blue.solid" borderRadius="xl" p={{ base: 4, md: 5 }}>
                                    <HStack justify="space-between" mb={2}>
                                        <Text fontWeight="700">Что изменится</Text>
                                        <Text fontSize="xs" color="fg.muted">на данных за {data.month_label.toLowerCase()}{previewing ? ' · считаем…' : ''}</Text>
                                    </HStack>
                                    <VStack align="stretch" gap={1} mb={3}>
                                        {Object.keys(changed).map((key) => {
                                            const meta = data.groups.flatMap((g) => g.params).find((p) => p.key === key);
                                            return (
                                                <Text key={key} fontSize="sm">
                                                    {meta?.label ?? key}: <Text as="span" color="fg.muted">{formatValue(meta?.type, data.values[key])}</Text> → <Text as="span" fontWeight="600">{formatValue(meta?.type, draft[key])}</Text>
                                                </Text>
                                            );
                                        })}
                                    </VStack>

                                    {errors.length > 0 && (
                                        <Alert status="error" title="Приказ не пройдёт проверку">
                                            <VStack align="start" gap={0}>{errors.map((e) => <Text key={e} fontSize="sm">{e}</Text>)}</VStack>
                                        </Alert>
                                    )}

                                    {preview && errors.length === 0 && (
                                        <Table.Root size="sm" mt={2}>
                                            <Table.Header>
                                                <Table.Row>
                                                    <Table.ColumnHeader>Работник</Table.ColumnHeader>
                                                    <Table.ColumnHeader textAlign="right">Сейчас</Table.ColumnHeader>
                                                    <Table.ColumnHeader textAlign="right">Станет</Table.ColumnHeader>
                                                    <Table.ColumnHeader textAlign="right">Изменение</Table.ColumnHeader>
                                                </Table.Row>
                                            </Table.Header>
                                            <Table.Body>
                                                {preview.rows.map((r) => (
                                                    <Table.Row key={r.manager_id}>
                                                        <Table.Cell><Text fontSize="sm">{r.name}</Text></Table.Cell>
                                                        <Table.Cell textAlign="right"><Text fontSize="sm" fontVariantNumeric="tabular-nums">{fmtRub(r.current, 0)}</Text></Table.Cell>
                                                        <Table.Cell textAlign="right"><Text fontSize="sm" fontVariantNumeric="tabular-nums">{fmtRub(r.projected, 0)}</Text></Table.Cell>
                                                        <Table.Cell textAlign="right"><Text fontSize="sm" fontWeight="700" color={r.delta >= 0 ? 'green.fg' : 'red.fg'} fontVariantNumeric="tabular-nums">{fmtSigned(r.delta)}</Text></Table.Cell>
                                                    </Table.Row>
                                                ))}
                                                <Table.Row bg="bg.subtle">
                                                    <Table.Cell><Text fontSize="sm" fontWeight="700">Фонд оплаты за месяц</Text></Table.Cell>
                                                    <Table.Cell textAlign="right"><Text fontSize="sm" fontVariantNumeric="tabular-nums">{fmtRub(preview.payroll.current, 0)}</Text></Table.Cell>
                                                    <Table.Cell textAlign="right"><Text fontSize="sm" fontVariantNumeric="tabular-nums">{fmtRub(preview.payroll.projected, 0)}</Text></Table.Cell>
                                                    <Table.Cell textAlign="right"><Text fontSize="sm" fontWeight="800" color={preview.payroll.delta >= 0 ? 'green.fg' : 'red.fg'} fontVariantNumeric="tabular-nums">{fmtSigned(preview.payroll.delta)}</Text></Table.Cell>
                                                </Table.Row>
                                            </Table.Body>
                                        </Table.Root>
                                    )}
                                    {(preview?.warnings ?? []).map((w) => <Text key={w} fontSize="xs" color="orange.fg" mt={1}>{w}</Text>)}
                                    {preview && preview.rows.length === 0 && errors.length === 0 && (
                                        <Text fontSize="sm" color="fg.muted">Считать не на чем: за этот месяц нет расчётов по новой схеме. Выберите другой период сверху.</Text>
                                    )}
                                </Box>

                                <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={{ base: 4, md: 5 }}>
                                    <Text fontWeight="700" mb={2}>Издать приказ</Text>
                                    <SimpleGrid columns={{ base: 1, sm: 2 }} gap={3} mb={3}>
                                        <Field label="Действует с периода">
                                            <input type="month" style={inputStyle} value={issue.effective_from} onChange={(e) => setIssue({ ...issue, effective_from: e.target.value })} aria-label="Действует с периода" />
                                        </Field>
                                        <Field label="Номер приказа">
                                            <input style={inputStyle} value={issue.order_number} onChange={(e) => setIssue({ ...issue, order_number: e.target.value })} placeholder="12-ОП" aria-label="Номер приказа" />
                                        </Field>
                                        <Field label="Дата приказа">
                                            <input type="date" style={inputStyle} value={issue.order_date} onChange={(e) => setIssue({ ...issue, order_date: e.target.value })} aria-label="Дата приказа" />
                                        </Field>
                                        <Field label="Основание">
                                            <input style={inputStyle} value={issue.comment} onChange={(e) => setIssue({ ...issue, comment: e.target.value })} placeholder="Зачем меняем" aria-label="Основание" />
                                        </Field>
                                    </SimpleGrid>
                                    <HStack justify="space-between">
                                        <Button size="sm" variant="ghost" onClick={() => setDraft(data.values)}>Отменить правки</Button>
                                        <Button size="sm" onClick={submitOrder} loading={issuing} disabled={errors.length > 0 || !issue.effective_from}><LuSave /> Издать приказ</Button>
                                    </HStack>
                                </Box>
                            </VStack>
                        )}
                    </SimpleGrid>
                )}

                {tab === 'history' && (
                    <VStack align="stretch" gap={3}>
                        {data.history.length === 0 && <Alert status="info" title="Приказов ещё не было">История появится после первого приказа.</Alert>}
                        {data.history.map((order) => (
                            <Box key={order.id} bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
                                <HStack gap={2} mb={2} flexWrap="wrap">
                                    <LuHistory size={14} />
                                    <Text fontWeight="700" fontSize="sm">с {fmtDay(order.effective_from)}</Text>
                                    <Text fontSize="sm" color="fg.muted">
                                        {order.order_number ? `№ ${order.order_number}` : 'без номера'}{order.order_date ? ` от ${fmtDay(order.order_date)}` : ''}{order.author ? ` · ${order.author}` : ''}
                                    </Text>
                                    {order.comment && <Text fontSize="sm" color="fg.muted">— {order.comment}</Text>}
                                </HStack>
                                {order.changes.length === 0
                                    ? <Text fontSize="xs" color="fg.subtle">Значения не отличаются от предыдущего набора.</Text>
                                    : (
                                        <VStack align="stretch" gap={0.5}>
                                            {order.changes.map((c) => {
                                                const meta = data.groups.flatMap((g) => g.params).find((p) => p.key === c.key);
                                                return (
                                                    <Text key={c.key} fontSize="sm">
                                                        {c.label}: <Text as="span" color="fg.muted">{formatValue(meta?.type, c.from)}</Text> → <Text as="span" fontWeight="600">{formatValue(meta?.type, c.to)}</Text>
                                                    </Text>
                                                );
                                            })}
                                        </VStack>
                                    )}
                            </Box>
                        ))}
                    </VStack>
                )}

                {tab === 'personal' && (
                    <PersonalOverrides
                        personal={data.personal}
                        managers={data.managers}
                        components={data.components}
                        canEdit={data.can_edit}
                        month={data.month.slice(0, 7)}
                        onChanged={(personal) => setData({ ...data, personal })}
                    />
                )}

                {!data.can_edit && (
                    <HStack fontSize="xs" color="fg.subtle" gap={1}>
                        <LuUser size={12} />
                        <Text>Вы видите действующие параметры. Изменить их может руководитель приказом.</Text>
                        <MetricHint text="Право crm-motivation.edit" />
                    </HStack>
                )}
            </VStack>
        </CrmLayout>
    );
}

function Field({ label, children }) {
    return (
        <VStack align="stretch" gap={1}>
            <Text fontSize="xs" color="fg.muted">{label}</Text>
            {children}
        </VStack>
    );
}

function nextMonth() {
    const d = new Date();
    d.setMonth(d.getMonth() + 1);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}
