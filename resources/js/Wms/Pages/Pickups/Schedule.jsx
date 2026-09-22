import { useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { Badge, Box, Card, HStack, Input, SimpleGrid, Table, Text, VStack } from '@chakra-ui/react';
import { LuPlus, LuSave } from 'react-icons/lu';
import WmsLayout from '@/Wms/Layouts/WmsLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';
import { Switch } from '@/components/ui/switch';
import { toaster } from '@/components/ui/toaster';
import { RowActionButton } from '@/shared/Panel/RowActions';
import { ConfirmDialog } from '@/shared/Panel/ConfirmDialog';
import { LuTrash2 } from 'react-icons/lu';
import { errorMessage } from './pickupUtils';

const DAYS = [[1, 'пн'], [2, 'вт'], [3, 'ср'], [4, 'чт'], [5, 'пт'], [6, 'сб'], [7, 'вс']];
const EMPTY_BREAK = { weekdays: [1, 2, 3, 4, 5, 6], starts_at: '13:00', ends_at: '14:00', label: 'обед', active: true };

function WeekdayPicker({ value, onChange, size = 'xs' }) {
    const toggle = (iso) => onChange(value.includes(iso) ? value.filter((d) => d !== iso) : [...value, iso].sort());
    return (
        <HStack gap="1" flexWrap="wrap">
            {DAYS.map(([iso, name]) => (
                <Button key={iso} size={size} variant={value.includes(iso) ? 'solid' : 'outline'} onClick={() => toggle(iso)} minW="9">{name}</Button>
            ))}
        </HStack>
    );
}

/**
 * График выдачи (pick-18): смена стойки и плановые технические перерывы. Начальник склада.
 *
 * Правило одно и показано сверху в предпросмотре: выдача закрыта только тогда, когда на стойке никого.
 * Два человека с обедом по очереди — курьер не ждёт; один человек — его обед виден курьеру и клиенту.
 */
export default function PickupSchedule() {
    const initial = usePage().props;
    const [data, setData] = useState(initial);
    const [busy, setBusy] = useState(null);
    const [confirm, setConfirm] = useState(null); // { title, description, run }
    const [newStaff, setNewStaff] = useState({ name: '', user_id: '', weekdays: [1, 2, 3, 4, 5, 6] });
    const [newBreak, setNewBreak] = useState({}); // staffId → форма

    const call = async (method, url, payload, key) => {
        setBusy(key);
        try {
            const { data: result } = await window.axios({ method, url, data: payload });
            toaster.create({ description: result.message, type: 'success' });
            setData((d) => ({ ...d, staff: result.staff, preview: result.preview, weekText: result.weekText }));
            return true;
        } catch (error) {
            toaster.create({ description: errorMessage(error), type: 'error' });
            return false;
        } finally {
            setBusy(null);
        }
    };

    const patchStaff = (id, patch) => setData((d) => ({ ...d, staff: d.staff.map((s) => (s.id === id ? { ...s, ...patch } : s)) }));
    const patchBreak = (staffId, id, patch) => setData((d) => ({
        ...d,
        staff: d.staff.map((s) => (s.id === staffId ? { ...s, breaks: s.breaks.map((b) => (b.id === id ? { ...b, ...patch } : b)) } : s)),
    }));

    const saveStaff = (s) => call('put', `/wms/pickups/schedule/staff/${s.id}`, { name: s.name, user_id: s.user_id || null, weekdays: s.weekdays, active: s.active }, `staff-${s.id}`);
    const saveBreak = (staffId, b) => call('put', `/wms/pickups/schedule/breaks/${b.id}`, b, `break-${b.id}`);
    const addStaff = async () => {
        if (await call('post', '/wms/pickups/schedule/staff', { ...newStaff, user_id: newStaff.user_id || null, active: true }, 'new-staff')) {
            setNewStaff({ name: '', user_id: '', weekdays: [1, 2, 3, 4, 5, 6] });
        }
    };
    const addBreak = async (staffId) => {
        const form = newBreak[staffId] || EMPTY_BREAK;
        if (await call('post', `/wms/pickups/schedule/staff/${staffId}/breaks`, form, `new-break-${staffId}`)) {
            setNewBreak((m) => ({ ...m, [staffId]: null }));
        }
    };

    const breakEditor = (form, onChange) => (
        <SimpleGrid columns={{ base: 1, md: 4 }} gap="2" alignItems="end">
            <Field label="Дни"><WeekdayPicker value={form.weekdays} onChange={(weekdays) => onChange({ weekdays })} /></Field>
            <HStack>
                <Field label="С"><Input size="sm" type="time" step="300" value={form.starts_at} onChange={(e) => onChange({ starts_at: e.target.value })} /></Field>
                <Field label="До"><Input size="sm" type="time" step="300" value={form.ends_at} onChange={(e) => onChange({ ends_at: e.target.value })} /></Field>
            </HStack>
            <Field label="Подпись для курьера"><Input size="sm" maxLength={60} value={form.label} onChange={(e) => onChange({ label: e.target.value })} placeholder="обед, почта…" /></Field>
        </SimpleGrid>
    );

    return (
        <WmsLayout breadcrumbs={[{ label: 'Выдача заказов', href: '/wms/pickups' }, { label: 'График выдачи' }]}>
            <Head title="График выдачи" />
            <ConfirmDialog open={!!confirm} onClose={() => setConfirm(null)} isLoading={busy === 'confirm'}
                title={confirm?.title} description={confirm?.description} confirmLabel="Удалить" colorPalette="red"
                onConfirm={async () => { setBusy('confirm'); await confirm.run(); setConfirm(null); }} />

            <VStack align="stretch" gap="4">
                <PageHeader title="График выдачи"
                    description="Смена стойки и технические перерывы. Выдача закрыта только когда на стойке никого: если двое обедают по очереди, курьер не ждёт. Курьер и клиент видят итог, а не перерывы каждого." />

                <Card.Root size="sm">
                    <Card.Body gap="2">
                        <Text fontWeight="700">Как это видят курьер и клиент</Text>
                        <Text color={data.weekText ? 'fg' : 'fg.muted'}>{data.weekText ? `Перерывы выдачи: ${data.weekText}` : 'По плану выдача без перерывов'}</Text>
                        <Table.Root size="sm" variant="line" mt="2">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeader>День</Table.ColumnHeader>
                                    <Table.ColumnHeader>Склад</Table.ColumnHeader>
                                    <Table.ColumnHeader>На смене</Table.ColumnHeader>
                                    <Table.ColumnHeader>Выдача закрыта</Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {data.preview.map((day) => (
                                    <Table.Row key={day.iso} opacity={day.works ? 1 : 0.5}>
                                        <Table.Cell>{day.name}</Table.Cell>
                                        <Table.Cell>{day.hours || 'выходной'}</Table.Cell>
                                        <Table.Cell>{day.works ? (day.roster.join(', ') || <Text as="span" color="fg.warning">смена не заведена</Text>) : '—'}</Table.Cell>
                                        <Table.Cell>
                                            {!day.works ? '—' : day.closed.length === 0
                                                ? <Badge colorPalette="green">без перерывов</Badge>
                                                : day.closed.map((w, i) => <Badge key={i} colorPalette="orange" mr="1">{w.from}–{w.to}{w.label ? ` ${w.label}` : ''}</Badge>)}
                                        </Table.Cell>
                                    </Table.Row>
                                ))}
                            </Table.Body>
                        </Table.Root>
                    </Card.Body>
                </Card.Root>

                <Text fontWeight="700" fontSize="lg">Смена стойки</Text>
                {data.staff.length === 0 && (
                    <Card.Root size="sm"><Card.Body><Text color="fg.muted">Никто не заведён — стойка считается открытой все часы склада, а «Отойти» закрывает её целиком.</Text></Card.Body></Card.Root>
                )}
                {data.staff.map((s) => (
                    <Card.Root key={s.id} size="sm" variant="outline" opacity={s.active ? 1 : 0.6}>
                        <Card.Body gap="3">
                            <SimpleGrid columns={{ base: 1, md: 4 }} gap="2" alignItems="end">
                                <Field label="Имя"><Input size="sm" value={s.name} onChange={(e) => patchStaff(s.id, { name: e.target.value })} /></Field>
                                <Field label="Учётка на сайте" helperText="Чтобы «Отойти» знало, кто нажал">
                                    <NativeSelectRoot size="sm">
                                        <NativeSelectField value={s.user_id || ''} onChange={(e) => patchStaff(s.id, { user_id: e.target.value ? Number(e.target.value) : null })}>
                                            <option value="">— без учётки —</option>
                                            {data.users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                                        </NativeSelectField>
                                    </NativeSelectRoot>
                                </Field>
                                <Field label="Рабочие дни"><WeekdayPicker value={s.weekdays} onChange={(weekdays) => patchStaff(s.id, { weekdays })} /></Field>
                                <HStack justify="space-between">
                                    <Switch checked={s.active} onCheckedChange={(e) => patchStaff(s.id, { active: e.checked })}>На стойке</Switch>
                                    <HStack gap="1">
                                        <Button size="sm" loading={busy === `staff-${s.id}`} onClick={() => saveStaff(s)}><LuSave /> Сохранить</Button>
                                        <RowActionButton icon={LuTrash2} label="Удалить" colorPalette="red"
                                            onClick={() => setConfirm({ title: `Убрать ${s.name} из смены?`, description: 'Перерывы сотрудника тоже удалятся. Чтобы убрать временно, выключите «На стойке».', run: () => call('delete', `/wms/pickups/schedule/staff/${s.id}`, null, `del-${s.id}`) })} />
                                    </HStack>
                                </HStack>
                            </SimpleGrid>

                            <Box borderTopWidth="1px" pt="2">
                                <Text fontSize="sm" fontWeight="700" mb="1">Перерывы</Text>
                                {s.breaks.length === 0 && !newBreak[s.id] && <Text fontSize="sm" color="fg.muted">Перерывов нет</Text>}
                                <VStack align="stretch" gap="2">
                                    {s.breaks.map((b) => (
                                        <HStack key={b.id} align="end" gap="2" flexWrap="wrap" opacity={b.active ? 1 : 0.6}>
                                            <Box flex="1" minW="0">{breakEditor(b, (patch) => patchBreak(s.id, b.id, patch))}</Box>
                                            <Switch size="sm" checked={b.active} onCheckedChange={(e) => patchBreak(s.id, b.id, { active: e.checked })}>Действует</Switch>
                                            <Button size="sm" variant="outline" loading={busy === `break-${b.id}`} onClick={() => saveBreak(s.id, b)}><LuSave /></Button>
                                            <RowActionButton icon={LuTrash2} label="Удалить перерыв" colorPalette="red"
                                                onClick={() => setConfirm({ title: 'Удалить перерыв?', description: `${b.starts_at}–${b.ends_at} ${b.label}`, run: () => call('delete', `/wms/pickups/schedule/breaks/${b.id}`, null, `del-break-${b.id}`) })} />
                                        </HStack>
                                    ))}
                                    {newBreak[s.id] ? (
                                        <HStack align="end" gap="2" flexWrap="wrap" bg="bg.subtle" p="2" borderRadius="md">
                                            <Box flex="1" minW="0">{breakEditor(newBreak[s.id], (patch) => setNewBreak((m) => ({ ...m, [s.id]: { ...m[s.id], ...patch } })))}</Box>
                                            <Button size="sm" loading={busy === `new-break-${s.id}`} onClick={() => addBreak(s.id)}>Добавить</Button>
                                            <Button size="sm" variant="ghost" onClick={() => setNewBreak((m) => ({ ...m, [s.id]: null }))}>Отмена</Button>
                                        </HStack>
                                    ) : (
                                        <Button size="xs" variant="ghost" alignSelf="flex-start" onClick={() => setNewBreak((m) => ({ ...m, [s.id]: { ...EMPTY_BREAK, weekdays: s.weekdays } }))}><LuPlus /> Перерыв</Button>
                                    )}
                                </VStack>
                            </Box>
                        </Card.Body>
                    </Card.Root>
                ))}

                <Card.Root size="sm" bg="bg.subtle">
                    <Card.Body gap="2">
                        <Text fontWeight="700">Добавить в смену</Text>
                        <SimpleGrid columns={{ base: 1, md: 4 }} gap="2" alignItems="end">
                            <Field label="Имя"><Input size="sm" value={newStaff.name} placeholder="Как зовут на складе" onChange={(e) => setNewStaff((f) => ({ ...f, name: e.target.value }))} /></Field>
                            <Field label="Учётка на сайте">
                                <NativeSelectRoot size="sm">
                                    <NativeSelectField value={newStaff.user_id} onChange={(e) => setNewStaff((f) => ({ ...f, user_id: e.target.value }))}>
                                        <option value="">— без учётки —</option>
                                        {data.users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                                    </NativeSelectField>
                                </NativeSelectRoot>
                            </Field>
                            <Field label="Рабочие дни"><WeekdayPicker value={newStaff.weekdays} onChange={(weekdays) => setNewStaff((f) => ({ ...f, weekdays }))} /></Field>
                            <Button size="sm" loading={busy === 'new-staff'} disabled={newStaff.name.trim().length < 2} onClick={addStaff}><LuPlus /> Добавить</Button>
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>
            </VStack>
        </WmsLayout>
    );
}
