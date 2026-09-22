import { useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { Badge, Box, HStack, Input, Table, Text, VStack } from '@chakra-ui/react';
import { LuPlus, LuSave, LuX } from 'react-icons/lu';
import WmsLayout from '@/Wms/Layouts/WmsLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { toaster } from '@/components/ui/toaster';
import { errorMessage } from './pickupUtils';

const NEW_BREAK = { from: '13:00', to: '14:00', label: 'обед' };

/**
 * График выдачи (pick-18): одна таблица «день недели → часы и перерывы», как её видят курьер и клиент.
 * Правится прямо в строках; сохранение построчно. Никаких смен и сотрудников — решение заказчика 22.09.2026.
 */
export default function PickupSchedule() {
    const initial = usePage().props;
    const [days, setDays] = useState(initial.days);
    const [weekText, setWeekText] = useState(initial.weekText);
    const [dirty, setDirty] = useState({});
    const [busy, setBusy] = useState(null);

    const patch = (iso, fn) => {
        setDays((list) => list.map((d) => (d.iso === iso ? fn(d) : d)));
        setDirty((m) => ({ ...m, [iso]: true }));
    };

    const save = async (day) => {
        setBusy(day.iso);
        try {
            const { data } = await window.axios.put(`/wms/pickups/schedule/days/${day.iso}`, {
                works: day.works, opens_at: day.opens_at, closes_at: day.closes_at, breaks: day.breaks,
            });
            toaster.create({ description: data.message, type: 'success' });
            setDays(data.days); setWeekText(data.weekText);
            setDirty((m) => ({ ...m, [day.iso]: false }));
        } catch (error) {
            toaster.create({ description: errorMessage(error), type: 'error' });
        } finally {
            setBusy(null);
        }
    };

    const timeInput = (value, onChange) => (
        <Input size="sm" type="time" step="300" w="104px" value={value || ''} onChange={(e) => onChange(e.target.value)} />
    );

    return (
        <WmsLayout breadcrumbs={[{ label: 'Выдача заказов', href: '/wms/pickups' }, { label: 'График выдачи' }]}>
            <Head title="График выдачи" />
            <VStack align="stretch" gap="4">
                <PageHeader title="График выдачи"
                    description="Часы выдачи и технические перерывы по дням недели — ровно то, что видят курьер в пропуске и клиент в кабинете. Правьте прямо в таблице и сохраняйте строку." />

                <Box bg="bg.panel" borderWidth="1px" borderRadius="xl" p="4">
                    <Text fontSize="sm" color="fg.muted">Так это читают курьер и клиент</Text>
                    <Text fontWeight="700">{initial.hoursText}</Text>
                    <Text>{weekText ? `Перерывы выдачи: ${weekText}` : 'Выдача без перерывов'}</Text>

                    <Table.Root size="sm" variant="line" mt="3">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeader>День</Table.ColumnHeader>
                                <Table.ColumnHeader>Выдаём</Table.ColumnHeader>
                                <Table.ColumnHeader>Часы</Table.ColumnHeader>
                                <Table.ColumnHeader>Перерывы (выдача закрыта)</Table.ColumnHeader>
                                <Table.ColumnHeader />
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {days.map((day) => (
                                <Table.Row key={day.iso} opacity={day.works ? 1 : 0.6}>
                                    <Table.Cell fontWeight="600" whiteSpace="nowrap">{day.name}</Table.Cell>
                                    <Table.Cell>
                                        <Switch size="sm" checked={day.works} onCheckedChange={(e) => patch(day.iso, (d) => ({ ...d, works: e.checked }))} />
                                    </Table.Cell>
                                    <Table.Cell>
                                        {day.works ? (
                                            <HStack gap="1">
                                                {timeInput(day.opens_at, (v) => patch(day.iso, (d) => ({ ...d, opens_at: v })))}
                                                <Text>–</Text>
                                                {timeInput(day.closes_at, (v) => patch(day.iso, (d) => ({ ...d, closes_at: v })))}
                                            </HStack>
                                        ) : <Text color="fg.muted">выходной</Text>}
                                    </Table.Cell>
                                    <Table.Cell>
                                        {day.works ? (
                                            <VStack align="stretch" gap="1">
                                                {day.breaks.map((b, i) => (
                                                    <HStack key={i} gap="1" flexWrap="wrap">
                                                        {timeInput(b.from, (v) => patch(day.iso, (d) => ({ ...d, breaks: d.breaks.map((x, j) => (j === i ? { ...x, from: v } : x)) })))}
                                                        <Text>–</Text>
                                                        {timeInput(b.to, (v) => patch(day.iso, (d) => ({ ...d, breaks: d.breaks.map((x, j) => (j === i ? { ...x, to: v } : x)) })))}
                                                        <Input size="sm" w="140px" maxLength={60} placeholder="обед, почта…" value={b.label}
                                                            onChange={(e) => patch(day.iso, (d) => ({ ...d, breaks: d.breaks.map((x, j) => (j === i ? { ...x, label: e.target.value } : x)) }))} />
                                                        <Button size="xs" variant="ghost" aria-label="Убрать перерыв"
                                                            onClick={() => patch(day.iso, (d) => ({ ...d, breaks: d.breaks.filter((_, j) => j !== i) }))}><LuX /></Button>
                                                    </HStack>
                                                ))}
                                                {day.breaks.length === 0 && !dirty[day.iso] && <Badge colorPalette="green" alignSelf="flex-start">без перерывов</Badge>}
                                                <Button size="xs" variant="ghost" alignSelf="flex-start"
                                                    onClick={() => patch(day.iso, (d) => ({ ...d, breaks: [...d.breaks, { ...NEW_BREAK }] }))}><LuPlus /> Перерыв</Button>
                                            </VStack>
                                        ) : <Text color="fg.muted">—</Text>}
                                    </Table.Cell>
                                    <Table.Cell textAlign="right">
                                        {dirty[day.iso] && (
                                            <Button size="sm" loading={busy === day.iso} onClick={() => save(day)}><LuSave /> Сохранить</Button>
                                        )}
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </Table.Root>
                </Box>

                <Text fontSize="sm" color="fg.muted">
                    Кладовщик отошёл внепланово — он жмёт «Отойти» на экране выдачи, и курьер видит «Перерыв до …». Это здесь не настраивается и попадает в журнал его ссылки.
                </Text>
            </VStack>
        </WmsLayout>
    );
}
