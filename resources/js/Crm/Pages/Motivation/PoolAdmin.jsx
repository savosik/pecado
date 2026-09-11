import { useEffect, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { Badge, Box, HStack, SimpleGrid, Table, Text, VStack } from '@chakra-ui/react';
import { LuChevronDown, LuChevronRight, LuPackagePlus, LuRefreshCw, LuUndo2 } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Pagination } from '@/Admin/Components/Pagination';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import MetricHint from '@/Crm/Components/MetricHint';
import { toastError, toastSuccess } from '@/utils/toast';
import { fmtDay, fmtDateTime, fmtRub0, plural } from '../Salary/components/format';

const selectStyle = {
    padding: '0.45rem 0.6rem',
    borderRadius: '0.5rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '180px',
    background: 'var(--chakra-colors-bg-panel)',
    color: 'var(--chakra-colors-fg)',
};

const OUTCOME = {
    in_progress: { label: 'в работе', palette: 'blue' },
    converted: { label: 'отгружен', palette: 'green' },
    returned: { label: 'возвращён', palette: 'gray' },
};

/**
 * Пул и раздача: кому и что раздать, кто просрочил сроки.
 *
 * Шапка обязана показывать, что из шести сотен партнёров покупали единицы:
 * раздача пакетами из такой базы даёт низкую конверсию.
 */
export default function MotivationPoolAdmin(props) {
    const [data, setData] = useState(props);
    const [busy, setBusy] = useState(false);
    const [managerId, setManagerId] = useState('');
    const [selected, setSelected] = useState(() => new Set());
    const [comment, setComment] = useState('');
    const [open, setOpen] = useState(() => new Set());
    const [toReturn, setToReturn] = useState({});

    useEffect(() => { setData(props); setSelected(new Set()); }, [props]);

    const navigate = (changes) => {
        const params = { ...data.query, ...changes };
        Object.keys(params).forEach((k) => { if (params[k] === '' || params[k] === null || params[k] === undefined) delete params[k]; });
        router.get('/crm/motivation/pool/admin', params, { preserveState: true, preserveScroll: true, replace: true });
    };

    const call = async (url, payload) => {
        setBusy(true);
        try {
            const res = await axios.post(url, payload);
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

    const toggleSelect = (id) => setSelected((prev) => {
        const n = new Set(prev);
        if (n.has(id)) n.delete(id); else if (n.size < data.state.package_size) n.add(id); else toastError(`Предельный размер пакета — ${data.state.package_size}`);
        return n;
    });
    const toggleOpen = (id) => setOpen((prev) => { const n = new Set(prev); if (n.has(id)) n.delete(id); else n.add(id); return n; });

    const candidates = data.candidates?.rows?.data ?? [];
    const tapFor = data.taps.find((t) => String(t.manager.id) === String(managerId));

    return (
        <CrmLayout breadcrumbs={[{ label: 'Мотивация' }, { label: 'Пул и раздача' }]}>
            <Head title="Пул и раздача — CRM" />
            <PageHeader title="Пул и раздача" description="Кому и что раздать, кто просрочил сроки. Карточка переходит работнику сразу, показатели — с первого числа следующего месяца." />

            <VStack align="stretch" gap={4}>
                <Alert status="warning" title="Пул холодный">{data.note}</Alert>

                <SimpleGrid columns={{ base: 2, md: 4 }} gap={3}>
                    <Stat label="В пуле" value={String(data.state.total)} />
                    <Stat label="С историей покупок" value={String(data.state.with_history)} />
                    <Stat label="Выдано в этом квартале" value={String(data.state.issued_this_quarter)} />
                    <Stat label="Возвращено в пул" value={String(data.state.returned_this_quarter)} />
                </SimpleGrid>

                <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
                    <HStack gap={1} mb={2}><Text fontWeight="700">Кран</Text><MetricHint text="Новый пакет не выдаётся, если отгрузки базы работника два периода подряд ниже порога оплаты (п. 8.4). Возобновляется с периода, следующего за тем, где порог достигнут." /></HStack>
                    <VStack align="stretch" gap={1}>
                        {data.taps.map((t) => (
                            <HStack key={t.manager.id} gap={2} fontSize="sm" flexWrap="wrap">
                                <Badge colorPalette={t.blocked ? 'red' : 'green'} variant="subtle">{t.blocked ? 'закрыт' : 'открыт'}</Badge>
                                <Text fontWeight="600">{t.manager.name}</Text>
                                <Text color="fg.muted">
                                    {t.checked.map((c) => `${c.month.slice(0, 7)}: ${c.base === null ? 'нет расчёта' : `${fmtRub0(c.base)} при пороге ${fmtRub0(c.threshold)}`}${c.below === true ? ' — ниже' : c.below === false ? ' — норма' : ''}`).join(' · ')}
                                </Text>
                                {t.note && <Text color="fg.subtle" fontSize="xs">{t.note}</Text>}
                            </HStack>
                        ))}
                    </VStack>
                </Box>

                <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflowX="auto">
                    <Text fontWeight="700" px={4} pt={3}>Выданные пакеты</Text>
                    {data.packages.length === 0 ? <Text px={4} pb={4} fontSize="sm" color="fg.muted">Пакетов ещё не выдавали.</Text> : (
                        <Table.Root size="sm">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeader w="1%" />
                                    <Table.ColumnHeader>Пакет</Table.ColumnHeader>
                                    <Table.ColumnHeader>Работник</Table.ColumnHeader>
                                    <Table.ColumnHeader>Выдан</Table.ColumnHeader>
                                    <Table.ColumnHeader>Контакт до / отгрузка до</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Партнёров</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Контакт в срок</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Отгружено</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Просрочено</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Действия</Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {data.packages.map((p) => [
                                    <Table.Row key={p.id}>
                                        <Table.Cell><Box as="button" type="button" color="fg.subtle" cursor="pointer" onClick={() => toggleOpen(p.id)} aria-expanded={open.has(p.id)} aria-label={`Состав пакета ${p.id}`}>{open.has(p.id) ? <LuChevronDown size={16} /> : <LuChevronRight size={16} />}</Box></Table.Cell>
                                        <Table.Cell><Text fontSize="sm" fontWeight="600">№ {p.id}</Text><Badge size="xs" variant="subtle" colorPalette={p.status === 'active' ? 'blue' : 'gray'}>{p.status === 'active' ? 'в работе' : p.status === 'closed' ? 'закрыт' : 'кран'}</Badge></Table.Cell>
                                        <Table.Cell><Text fontSize="sm">{p.manager.name}</Text></Table.Cell>
                                        <Table.Cell><Text fontSize="sm">{fmtDay(p.issued_on)}</Text></Table.Cell>
                                        <Table.Cell><Text fontSize="xs">{fmtDay(p.contact_due_on)} / {fmtDay(p.shipment_due_on)}</Text></Table.Cell>
                                        <Table.Cell textAlign="right"><Text fontSize="sm">{p.count}</Text></Table.Cell>
                                        <Table.Cell textAlign="right"><Text fontSize="sm">{p.contacted_in_time}</Text></Table.Cell>
                                        <Table.Cell textAlign="right"><Text fontSize="sm" color={p.shipped > 0 ? 'green.fg' : undefined}>{p.shipped}</Text></Table.Cell>
                                        <Table.Cell textAlign="right"><Text fontSize="sm" color={p.overdue > 0 ? 'red.fg' : 'fg.subtle'}>{p.overdue}</Text></Table.Cell>
                                        <Table.Cell textAlign="right">
                                            {data.can_edit && (
                                                <HStack justify="flex-end" gap={1}>
                                                    <Button size="xs" variant="ghost" aria-label="Обновить состояние" title="Обновить по данным CRM" loading={busy} onClick={() => call(`/crm/motivation/pool/packages/${p.id}/refresh`, {})}><LuRefreshCw /></Button>
                                                    {p.overdue > 0 && (
                                                        <Button size="xs" variant="outline" colorPalette="orange" loading={busy} onClick={() => call(`/crm/motivation/pool/packages/${p.id}/return`, { item_ids: p.items.filter((i) => i.late).map((i) => i.id) })}><LuUndo2 /> Вернуть просроченных</Button>
                                                    )}
                                                </HStack>
                                            )}
                                        </Table.Cell>
                                    </Table.Row>,
                                    open.has(p.id) && (
                                        <Table.Row key={`${p.id}-items`}>
                                            <Table.Cell colSpan={10} bg="bg.subtle" p={0}>
                                                <Table.Root size="sm" variant="line">
                                                    <Table.Body>
                                                        {p.items.map((i) => (
                                                            <Table.Row key={i.id}>
                                                                <Table.Cell pl={12}>
                                                                    <HStack gap={2}>
                                                                        {data.can_edit && i.outcome !== 'returned' && <input type="checkbox" aria-label="К возврату" checked={Boolean(toReturn[i.id])} onChange={(e) => setToReturn({ ...toReturn, [i.id]: e.target.checked })} />}
                                                                        <Text fontSize="sm">{i.partner_name}</Text>
                                                                    </HStack>
                                                                </Table.Cell>
                                                                <Table.Cell><Text fontSize="xs" color={i.contact_in_time ? 'green.fg' : 'fg.muted'}>{i.first_contact_at ? `контакт ${fmtDateTime(i.first_contact_at)}` : 'контакта нет'}</Text></Table.Cell>
                                                                <Table.Cell><Text fontSize="xs" color={i.first_shipment_at ? 'green.fg' : 'fg.muted'}>{i.first_shipment_at ? `отгрузка ${fmtDateTime(i.first_shipment_at)}` : 'отгрузки нет'}</Text></Table.Cell>
                                                                <Table.Cell><Badge size="xs" variant="subtle" colorPalette={i.late ? 'red' : OUTCOME[i.outcome]?.palette}>{i.late ? 'просрочен' : OUTCOME[i.outcome]?.label}</Badge></Table.Cell>
                                                            </Table.Row>
                                                        ))}
                                                        {data.can_edit && p.items.some((i) => toReturn[i.id]) && (
                                                            <Table.Row>
                                                                <Table.Cell colSpan={4} textAlign="right">
                                                                    <Button size="xs" variant="outline" colorPalette="orange" loading={busy} onClick={async () => { if (await call(`/crm/motivation/pool/packages/${p.id}/return`, { item_ids: p.items.filter((i) => toReturn[i.id]).map((i) => i.id) })) setToReturn({}); }}><LuUndo2 /> Вернуть выбранных в пул</Button>
                                                                </Table.Cell>
                                                            </Table.Row>
                                                        )}
                                                    </Table.Body>
                                                </Table.Root>
                                            </Table.Cell>
                                        </Table.Row>
                                    ),
                                ])}
                            </Table.Body>
                        </Table.Root>
                    )}
                </Box>

                {data.can_edit && (
                    <Box bg="bg.panel" borderWidth="2px" borderColor={selected.size > 0 ? 'blue.solid' : 'border'} borderRadius="xl" p={4}>
                        <HStack justify="space-between" flexWrap="wrap" gap={2} mb={3}>
                            <HStack gap={2}>
                                <Text fontWeight="700">Сформировать пакет</Text>
                                <MetricHint text={`До ${data.state.package_size} партнёров. Контакт — за ${data.state.contact_working_days} рабочих дней, первая отгрузка — за ${data.state.shipment_days} календарных. Сроки из приказа.`} />
                            </HStack>
                            <HStack gap={2} flexWrap="wrap">
                                <select aria-label="Работник" style={selectStyle} value={managerId} onChange={(e) => setManagerId(e.target.value)}>
                                    <option value="">Кому выдать…</option>
                                    {data.taps.map((t) => <option key={t.manager.id} value={t.manager.id} disabled={t.blocked}>{t.manager.name}{t.blocked ? ' — кран закрыт' : ''}</option>)}
                                </select>
                                <input style={selectStyle} value={comment} aria-label="Комментарий к пакету" placeholder="Комментарий" onChange={(e) => setComment(e.target.value)} />
                                <Button size="sm" loading={busy} disabled={!managerId || selected.size === 0 || tapFor?.blocked} onClick={async () => { if (await call('/crm/motivation/pool/packages', { manager_id: Number(managerId), partner_ids: [...selected], comment })) { setSelected(new Set()); setComment(''); } }}>
                                    <LuPackagePlus /> Выдать {selected.size > 0 ? `${selected.size} ${plural(selected.size, 'партнёра', 'партнёров', 'партнёров')}` : 'пакет'}
                                </Button>
                            </HStack>
                        </HStack>

                        <HStack gap={2} flexWrap="wrap" mb={2}>
                            <Box as="button" type="button" px={3} py={1} borderRadius="full" borderWidth="1px" borderColor={Number(data.query.history ?? 1) ? 'blue.solid' : 'border'} bg={Number(data.query.history ?? 1) ? 'blue.subtle' : 'bg.panel'} fontSize="sm" cursor="pointer" onClick={() => navigate({ history: Number(data.query.history ?? 1) ? 0 : 1, page: undefined })}>
                                Только с историей покупок · {data.state.with_history}
                            </Box>
                            <input type="search" aria-label="Поиск" placeholder="Название или город…" style={selectStyle} defaultValue={data.query.search ?? ''} onKeyDown={(e) => { if (e.key === 'Enter') navigate({ search: e.target.value, page: undefined }); }} />
                        </HStack>

                        {candidates.length === 0 ? <Text fontSize="sm" color="fg.muted">По этому фильтру партнёров нет.</Text> : (
                            <Box overflowX="auto">
                                <Table.Root size="sm">
                                    <Table.Header>
                                        <Table.Row>
                                            <Table.ColumnHeader w="1%" />
                                            <Table.ColumnHeader>Партнёр</Table.ColumnHeader>
                                            <Table.ColumnHeader>Город</Table.ColumnHeader>
                                            <Table.ColumnHeader>Последняя покупка</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="right">Брал в месяц</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="right">Лучший месяц</Table.ColumnHeader>
                                            <Table.ColumnHeader>Приоритет</Table.ColumnHeader>
                                        </Table.Row>
                                    </Table.Header>
                                    <Table.Body>
                                        {candidates.map((c) => (
                                            <Table.Row key={c.id} bg={selected.has(c.id) ? 'blue.subtle' : undefined}>
                                                <Table.Cell><input type="checkbox" aria-label={`Выбрать ${c.name}`} checked={selected.has(c.id)} onChange={() => toggleSelect(c.id)} /></Table.Cell>
                                                <Table.Cell><Text fontSize="sm" fontWeight="600">{c.name}</Text>{c.legal_name && c.legal_name !== c.name && <Text fontSize="xs" color="fg.subtle">{c.legal_name}</Text>}</Table.Cell>
                                                <Table.Cell><Text fontSize="sm">{c.city || '—'}</Text></Table.Cell>
                                                <Table.Cell>{c.last_purchase_on ? <Text fontSize="sm">{fmtDay(c.last_purchase_on)}</Text> : <Badge size="xs" variant="subtle">никогда</Badge>}</Table.Cell>
                                                <Table.Cell textAlign="right"><Text fontSize="sm">{c.ever_bought && c.usual_monthly > 0 ? fmtRub0(c.usual_monthly) : '—'}</Text></Table.Cell>
                                                <Table.Cell textAlign="right"><Text fontSize="sm">{c.ever_bought && c.best_month?.amount ? fmtRub0(c.best_month.amount) : '—'}</Text></Table.Cell>
                                                <Table.Cell><Badge size="xs" variant="subtle" colorPalette={c.ever_bought ? 'green' : 'gray'}>{c.ever_bought ? 'есть история' : 'холодный'}</Badge></Table.Cell>
                                            </Table.Row>
                                        ))}
                                    </Table.Body>
                                </Table.Root>
                                <Pagination pagination={data.candidates.rows} onPageChange={(page) => navigate({ page })} />
                            </Box>
                        )}
                    </Box>
                )}
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
