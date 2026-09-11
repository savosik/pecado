import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Badge, Box, HStack, SimpleGrid, Table, Text, VStack } from '@chakra-ui/react';
import { LuChevronDown, LuChevronRight } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Pagination } from '@/Admin/Components/Pagination';
import { Alert } from '@/components/ui/alert';
import { fmtDay, fmtRub0 } from '../Salary/components/format';
import MotivationTabs from './components/MotivationTabs';
import { hubBreadcrumbs } from './components/hubs';

const inputStyle = {
    padding: '0.45rem 0.6rem',
    borderRadius: '0.5rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '140px',
    background: 'var(--chakra-colors-bg-panel)',
    color: 'var(--chakra-colors-fg)',
};

/**
 * Журнал скидок: какие ручные скидки выданы и кем. Постконтроль — на показатели
 * не влияет и отгрузки не блокирует.
 */
export default function MotivationDiscounts({ query, managers, summary, rows, note }) {
    const [open, setOpen] = useState(() => new Set());
    const navigate = (changes) => {
        const params = { ...query, ...changes };
        Object.keys(params).forEach((k) => { if (params[k] === '' || params[k] === null || params[k] === undefined) delete params[k]; });
        router.get('/crm/motivation/discounts', params, { preserveState: true, preserveScroll: true, replace: true });
    };
    const toggle = (id) => setOpen((prev) => { const n = new Set(prev); if (n.has(id)) n.delete(id); else n.add(id); return n; });
    const docs = rows?.data ?? [];

    return (
        <CrmLayout breadcrumbs={hubBreadcrumbs('debts', 'discounts')}>
            <Head title="Журнал скидок — CRM" />
            <PageHeader title="Журнал скидок" description="Какие ручные скидки выданы и кем. Сортировка — по размеру скидки, по убыванию." />
            <MotivationTabs hub="debts" current="discounts" />

            <VStack align="stretch" gap={4}>
                <Alert status="info" title="Постконтроль">{note}</Alert>

                <HStack gap={2} flexWrap="wrap">
                    <input type="date" aria-label="С даты" style={inputStyle} value={query.from} onChange={(e) => navigate({ from: e.target.value, page: undefined })} />
                    <input type="date" aria-label="По дату" style={inputStyle} value={query.to} onChange={(e) => navigate({ to: e.target.value, page: undefined })} />
                    <select aria-label="Работник" style={inputStyle} value={query.manager ?? ''} onChange={(e) => navigate({ manager: e.target.value || undefined, page: undefined })}>
                        <option value="">Все работники</option>
                        {managers.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                    </select>
                    <select aria-label="Скидка от" style={inputStyle} value={query.min_percent} onChange={(e) => navigate({ min_percent: e.target.value, page: undefined })}>
                        {[0, 5, 10, 20, 30, 50].map((p) => <option key={p} value={p}>{p === 0 ? 'любая скидка' : `скидка от ${p} %`}</option>)}
                    </select>
                </HStack>

                <SimpleGrid columns={{ base: 2, md: 4 }} gap={3}>
                    <Stat label="Документов со скидкой" value={String(summary.documents)} hint={`${summary.lines} строк`} />
                    <Stat label="Партнёров" value={String(summary.partners)} />
                    <Stat label="Максимальная скидка" value={`${summary.max_percent.toLocaleString('ru-RU')} %`} />
                    <Stat label="Сумма скидок" value={fmtRub0(summary.discount)} />
                </SimpleGrid>

                <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflowX="auto">
                    {docs.length === 0 ? <Text p={4} fontSize="sm" color="fg.muted">Ручных скидок за период нет.</Text> : (
                        <>
                            <Table.Root size="sm">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader w="1%" />
                                        <Table.ColumnHeader>Документ</Table.ColumnHeader>
                                        <Table.ColumnHeader>Партнёр</Table.ColumnHeader>
                                        <Table.ColumnHeader>Работник</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">Скидка до</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">Сумма скидки</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">Итог документа</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {docs.map((d) => [
                                        <Table.Row key={d.shipment_id}>
                                            <Table.Cell><Box as="button" type="button" cursor="pointer" color="fg.subtle" aria-expanded={open.has(d.shipment_id)} aria-label={`Позиции документа ${d.number}`} onClick={() => toggle(d.shipment_id)}>{open.has(d.shipment_id) ? <LuChevronDown size={16} /> : <LuChevronRight size={16} />}</Box></Table.Cell>
                                            <Table.Cell><Text fontSize="sm" fontWeight="600">{d.number}</Text><Text fontSize="xs" color="fg.subtle">{fmtDay(d.date)}</Text></Table.Cell>
                                            <Table.Cell><Text fontSize="sm">{d.partner_name}</Text></Table.Cell>
                                            <Table.Cell><Text fontSize="sm">{d.manager_name || '—'}</Text></Table.Cell>
                                            <Table.Cell textAlign="right"><Badge size="sm" variant="subtle" colorPalette={d.max_percent >= 30 ? 'red' : d.max_percent >= 10 ? 'orange' : 'gray'}>{d.max_percent.toLocaleString('ru-RU')} %</Badge></Table.Cell>
                                            <Table.Cell textAlign="right"><Text fontSize="sm" fontWeight="700" fontVariantNumeric="tabular-nums">{fmtRub0(d.discount)}</Text></Table.Cell>
                                            <Table.Cell textAlign="right"><Text fontSize="sm" fontVariantNumeric="tabular-nums">{fmtRub0(d.total)}</Text></Table.Cell>
                                        </Table.Row>,
                                        open.has(d.shipment_id) && (
                                            <Table.Row key={`${d.shipment_id}-items`}>
                                                <Table.Cell colSpan={7} bg="bg.subtle" p={0}>
                                                    <Table.Root size="sm" variant="line">
                                                        <Table.Body>
                                                            {d.items.map((i) => (
                                                                <Table.Row key={i.id}>
                                                                    <Table.Cell pl={12}><Text fontSize="sm">{i.name}</Text></Table.Cell>
                                                                    <Table.Cell><Text fontSize="xs" color="fg.muted">{i.quantity.toLocaleString('ru-RU')} × {fmtRub0(i.price)}</Text></Table.Cell>
                                                                    <Table.Cell textAlign="right"><Text fontSize="xs">ручная {i.percent.toLocaleString('ru-RU')} %{i.auto_percent > 0 ? ` · авто ${i.auto_percent.toLocaleString('ru-RU')} %` : ''}</Text></Table.Cell>
                                                                    <Table.Cell textAlign="right"><Text fontSize="sm" fontVariantNumeric="tabular-nums">−{fmtRub0(i.discount)}</Text></Table.Cell>
                                                                    <Table.Cell textAlign="right"><Text fontSize="sm" color="fg.muted" fontVariantNumeric="tabular-nums">{fmtRub0(i.total)}</Text></Table.Cell>
                                                                </Table.Row>
                                                            ))}
                                                        </Table.Body>
                                                    </Table.Root>
                                                </Table.Cell>
                                            </Table.Row>
                                        ),
                                    ])}
                                </Table.Body>
                            </Table.Root>
                            <Pagination pagination={rows} onPageChange={(page) => navigate({ page })} />
                        </>
                    )}
                </Box>
            </VStack>
        </CrmLayout>
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
