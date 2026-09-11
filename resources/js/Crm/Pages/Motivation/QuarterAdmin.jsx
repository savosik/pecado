import { useEffect, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { Badge, Box, HStack, SimpleGrid, Table, Text, VStack } from '@chakra-ui/react';
import { LuCheck, LuLock, LuRefreshCw, LuUndo2, LuWallet } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import MetricHint from '@/Crm/Components/MetricHint';
import { toastError, toastSuccess } from '@/utils/toast';
import { fmtDay, fmtRub0, plural } from '../Salary/components/format';
import MotivationTabs from './components/MotivationTabs';
import { hubBreadcrumbs } from './components/hubs';
import FoldSection from './components/FoldSection';

const inputStyle = {
    padding: '0.45rem 0.6rem',
    borderRadius: '0.5rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '150px',
    background: 'var(--chakra-colors-bg-panel)',
    color: 'var(--chakra-colors-fg)',
};

/**
 * Квартальная премия — экран руководителя: взята ли ступень, кто вошёл,
 * утверждение итога и распределение по работникам с контролем суммы (п. 7.5).
 */
export default function MotivationQuarterAdmin(props) {
    const [state, setState] = useState(props);
    const [busy, setBusy] = useState(false);
    const [shares, setShares] = useState({});
    const [reason, setReason] = useState('');

    useEffect(() => {
        setState(props);
        const initial = {};
        props.distribution.rows.forEach((r) => { initial[r.manager.id] = r.amount ?? r.suggested; });
        setShares(initial);
        setReason(props.distribution.rows.find((r) => r.reason)?.reason ?? '');
    }, [props]);

    const { data, bonus, distribution, quarter_options: quarterOptions, can_edit: canEdit } = state;
    const quarter = data.quarter.slice(0, 7);
    const navigate = (q) => router.get('/crm/motivation/quarter/admin', { quarter: q }, { preserveState: true, preserveScroll: true, replace: true });

    const call = async (url, payload = {}) => {
        setBusy(true);
        try {
            const res = await axios.post(url, payload, { params: { quarter } });
            setState(res.data);
            const initial = {};
            res.data.distribution.rows.forEach((r) => { initial[r.manager.id] = r.amount ?? r.suggested; });
            setShares(initial);
            if (res.data.message) toastSuccess(res.data.message);
        } catch (e) {
            toastError(e.response?.data?.message ?? Object.values(e.response?.data?.errors ?? {}).flat().join(' ') ?? 'Не удалось выполнить');
        } finally {
            setBusy(false);
        }
    };

    const sharesTotal = Object.values(shares).reduce((s, v) => s + (Number(v) || 0), 0);
    const diff = Math.round((sharesTotal - data.amount) * 100) / 100;

    return (
        <CrmLayout breadcrumbs={hubBreadcrumbs('ledger', 'quarter')}>
            <Head title="Квартальная премия — CRM" />
            <PageHeader
                title="Квартальная премия"
                description="Взята ли ступень и кто в неё вошёл. Итог утверждается руками, распределение — решение руководителя (п. 7.5)."
                actions={(
                    <select aria-label="Квартал" style={inputStyle} value={quarter} onChange={(e) => navigate(e.target.value)}>
                        {quarterOptions.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                    </select>
                )}
            />
            <MotivationTabs hub="ledger" current="quarter" />

            <VStack align="stretch" gap={4}>
                <SimpleGrid columns={{ base: 2, md: 4 }} gap={3}>
                    <Stat label="Засчитано партнёров" value={`${data.qualified_count} из ${data.candidates_count}`} hint={`порог ${fmtRub0(data.qualification_amount)} на партнёра`} />
                    <Stat label="Взятая ступень" value={data.step_reached > 0 ? `${data.step_reached} · ${fmtRub0(data.amount)}` : 'пока нет'} tone={data.step_reached > 0 ? 'green' : undefined} />
                    <Stat label={data.days_left > 0 ? 'До конца квартала' : 'Квартал'} value={data.days_left > 0 ? `${data.days_left} ${plural(data.days_left, 'день', 'дня', 'дней')}` : 'завершён'} />
                    <Stat label="Статус" value={bonus ? bonus.status_label : 'не считалась'} hint={bonus?.approved_at ? `утверждена ${fmtDay(bonus.approved_at)}` : bonus?.computed_at ? `посчитана ${fmtDay(bonus.computed_at)}` : null} />
                </SimpleGrid>

                <SimpleGrid columns={{ base: 1, md: 3 }} gap={3}>
                    {data.steps.map((step, i) => (
                        <Box key={step.count} bg="bg.panel" borderWidth="2px" borderColor={step.reached ? 'green.solid' : 'border'} borderRadius="xl" p={4}>
                            <HStack justify="space-between" mb={1}><Text fontSize="xs" color="fg.muted">Ступень {i + 1}</Text>{step.reached && <Badge colorPalette="green" variant="subtle" size="xs">взята</Badge>}</HStack>
                            <Text fontSize="sm"><b>{step.count} новых {plural(step.count, 'партнёр', 'партнёра', 'партнёров')}</b>, каждый купил за квартал не менее {fmtRub0(data.qualification_amount)}</Text>
                            <Text fontSize="xl" fontWeight="800" mt={1} color={step.reached ? 'green.fg' : undefined}>→ {fmtRub0(step.amount)} на отдел</Text>
                        </Box>
                    ))}
                </SimpleGrid>

                {canEdit && (
                    <HStack gap={2} flexWrap="wrap">
                        {(!bonus || bonus.status === 'draft') && <Button size="sm" variant="outline" loading={busy} onClick={() => call('/crm/motivation/quarter/recalculate')}><LuRefreshCw /> Пересчитать</Button>}
                        {bonus?.status === 'draft' && <Button size="sm" loading={busy} onClick={() => call(`/crm/motivation/quarter/${bonus.id}/approve`)}><LuLock /> Утвердить итог квартала</Button>}
                        {bonus?.status === 'approved' && <Button size="sm" colorPalette="green" loading={busy} disabled={!distribution.complete && data.amount > 0} title={!distribution.complete && data.amount > 0 ? 'Сначала распределите премию' : undefined} onClick={() => call(`/crm/motivation/quarter/${bonus.id}/paid`)}><LuWallet /> Отметить выплаченной</Button>}
                        {bonus && bonus.status !== 'draft' && <Button size="sm" variant="ghost" loading={busy} onClick={() => call(`/crm/motivation/quarter/${bonus.id}/reopen`)}><LuUndo2 /> Переоткрыть</Button>}
                    </HStack>
                )}

                {bonus && bonus.status !== 'draft' && data.amount > 0 && (
                    <Box bg="bg.panel" borderWidth="2px" borderColor={distribution.complete ? 'green.solid' : 'orange.solid'} borderRadius="xl" p={4}>
                        <HStack gap={1} mb={2}><Text fontWeight="700">Распределение между работниками</Text><MetricHint text="Подсказка — вклад каждого в засчитанных партнёрах. Распределение не обязано ей следовать: п. 7.5 оставляет это решению руководителя. Сумма строк обязана равняться сумме премии." /></HStack>
                        <Table.Root size="sm">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeader>Работник</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Привёл засчитанных</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Подсказка</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Доля, ₽</Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {distribution.rows.map((r) => (
                                    <Table.Row key={r.manager.id}>
                                        <Table.Cell><Text fontSize="sm" fontWeight="600">{r.manager.name}</Text>{r.author && <Text fontSize="xs" color="fg.subtle">разнёс {r.author}</Text>}</Table.Cell>
                                        <Table.Cell textAlign="right"><Text fontSize="sm">{r.qualified}</Text></Table.Cell>
                                        <Table.Cell textAlign="right"><Text fontSize="sm" color="fg.muted">{fmtRub0(r.suggested)}</Text></Table.Cell>
                                        <Table.Cell textAlign="right">
                                            {canEdit && bonus.status === 'approved'
                                                ? <input type="number" min="0" step="1" aria-label={`Доля ${r.manager.name}`} style={{ ...inputStyle, minWidth: '130px', textAlign: 'right' }} value={shares[r.manager.id] ?? ''} onChange={(e) => setShares({ ...shares, [r.manager.id]: e.target.value })} />
                                                : <Text fontSize="sm" fontWeight="700">{r.amount === null ? '—' : fmtRub0(r.amount)}</Text>}
                                        </Table.Cell>
                                    </Table.Row>
                                ))}
                                <Table.Row bg="bg.subtle">
                                    <Table.Cell colSpan={3}><Text fontWeight="700">Итого · премия {fmtRub0(data.amount)}</Text></Table.Cell>
                                    <Table.Cell textAlign="right">
                                        <Text fontWeight="800" color={Math.abs(diff) < 0.005 ? 'green.fg' : 'red.fg'}>{fmtRub0(sharesTotal)}</Text>
                                        {Math.abs(diff) >= 0.005 && <Text fontSize="xs" color="red.fg">расхождение {diff > 0 ? '+' : ''}{fmtRub0(diff)}</Text>}
                                    </Table.Cell>
                                </Table.Row>
                            </Table.Body>
                        </Table.Root>
                        {canEdit && bonus.status === 'approved' && (
                            <HStack mt={3} gap={2} flexWrap="wrap">
                                <input style={{ ...inputStyle, flex: 1 }} placeholder="Основание распределения (попадёт в расчётный лист)" maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />
                                <Button size="sm" loading={busy} disabled={Math.abs(diff) >= 0.005} onClick={() => call(`/crm/motivation/quarter/${bonus.id}/distribute`, { shares: distribution.rows.map((r) => ({ manager_id: r.manager.id, amount: Number(shares[r.manager.id]) || 0 })), reason })}><LuCheck /> Сохранить распределение</Button>
                            </HStack>
                        )}
                    </Box>
                )}

                <FoldSection title={'Кандидаты'} summary={`${data.candidates.length} ${plural(data.candidates.length, 'партнёр', 'партнёра', 'партнёров')}`}>
                    <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflowX="auto">
                        {data.candidates.length === 0 ? <Text px={4} pb={4} fontSize="sm" color="fg.muted">Новых партнёров с отгрузками в этом квартале нет.</Text> : (
                            <Table.Root size="sm">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>Партнёр</Table.ColumnHeader>
                                        <Table.ColumnHeader>Работник</Table.ColumnHeader>
                                        <Table.ColumnHeader>Первая покупка</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">Куплено за квартал</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">Зачёт</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {data.candidates.map((c) => (
                                        <Table.Row key={c.partner_id}>
                                            <Table.Cell><Link href={`/crm/partners/${c.partner_id}`}><Text fontSize="sm" fontWeight="600" _hover={{ textDecoration: 'underline' }}>{c.name}</Text></Link></Table.Cell>
                                            <Table.Cell><Text fontSize="sm" color="fg.muted">{c.manager ?? '—'}</Text></Table.Cell>
                                            <Table.Cell><Text fontSize="sm">{c.first_purchase_on ? fmtDay(c.first_purchase_on) : '—'}</Text></Table.Cell>
                                            <Table.Cell textAlign="right"><Text fontSize="sm" fontVariantNumeric="tabular-nums">{fmtRub0(c.quarter_amount)}</Text></Table.Cell>
                                            <Table.Cell textAlign="right">{c.qualified ? <Badge size="xs" colorPalette="green" variant="subtle">засчитан</Badge> : <Text fontSize="sm" color="fg.muted">ещё {fmtRub0(c.shortfall)}</Text>}</Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        )}
                    </Box>
                </FoldSection>

                <Alert status="info" title="Премия начисляется на отдел">{data.note} Разнесённая доля попадает в расчётный лист работника за последний месяц квартала отдельной строкой и на показатели раздела 6 не влияет (п. 7.6).</Alert>
            </VStack>
        </CrmLayout>
    );
}

function Stat({ label, value, tone, hint }) {
    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
            <Text fontSize="xs" color="fg.muted">{label}</Text>
            <Text fontSize="2xl" fontWeight="800" fontVariantNumeric="tabular-nums" color={tone ? `${tone}.fg` : undefined}>{value}</Text>
            {hint && <Text fontSize="xs" color="fg.subtle">{hint}</Text>}
        </Box>
    );
}
