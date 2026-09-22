import { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Box, Dialog, HStack, Input, Portal, Text, VStack } from '@chakra-ui/react';
import { LuCalendarClock, LuCoffee, LuDoorOpen, LuUndo2 } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { toaster } from '@/components/ui/toaster';
import { usePermission } from '@/shared/Panel/usePermission';
import { errorMessage } from './pickupUtils';

const REASONS = ['обед', 'почта', 'отгрузка', 'приёмка', 'другое'];
const DURATIONS = [10, 20, 30, 60];

const STATE_STYLE = {
    open: { bg: 'green.subtle', border: 'green.muted', color: 'green.fg' },
    break: { bg: 'orange.subtle', border: 'orange.muted', color: 'orange.fg' },
    closed: { bg: 'bg.muted', border: 'border', color: 'fg.muted' },
};

/**
 * Стойка выдачи на экране склада (pick-18): выдают сейчас или нет, «Отойти» и «Вернулся».
 *
 * То же самое в этот момент видят курьер на странице пропуска и клиент в кабинете, поэтому
 * кладовщик, уходя на почту или обедать, нажимает «Отойти» — и к нему никто не «припрётся».
 * Если на смене двое, отлучка одного выдачу не закрывает: об этом говорит текст под статусом.
 */
export default function DeskBar({ desk, canIssue, onChange }) {
    const { auth } = usePage().props;
    const { can } = usePermission();
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [form, setForm] = useState({ staff_id: '', minutes: 20, until_close: false, reason: 'обед', custom: '' });

    if (!desk) return null;
    const style = STATE_STYLE[desk.state] || STATE_STYLE.closed;
    const roster = desk.roster || [];
    const pauses = desk.pauses || [];
    const myStaff = roster.find((s) => s.user_id && s.user_id === auth?.user?.id);

    const openDialog = () => {
        setForm({ staff_id: String(myStaff?.id ?? (roster.length === 1 ? roster[0].id : '')), minutes: 20, until_close: false, reason: 'обед', custom: '' });
        setOpen(true);
    };

    const post = async (url, payload) => {
        setBusy(true);
        try {
            const { data } = await window.axios.post(url, payload);
            toaster.create({ description: data.message, type: 'success' });
            onChange?.(data.desk);
            return true;
        } catch (error) {
            toaster.create({ description: errorMessage(error), type: 'error' });
            return false;
        } finally {
            setBusy(false);
        }
    };

    const submitPause = async () => {
        const reason = form.reason === 'другое' ? form.custom.trim() : form.reason;
        if (!reason) { toaster.create({ description: 'Укажите причину', type: 'info' }); return; }
        // Со смены из двоих без выбора человека отлучка закрыла бы стойку целиком — не даём промахнуться.
        if (roster.length > 0 && !form.staff_id) { toaster.create({ description: 'Выберите, кто отходит', type: 'info' }); return; }
        const ok = await post('/wms/pickups/desk/pause', {
            staff_id: form.staff_id ? Number(form.staff_id) : null,
            minutes: form.until_close ? null : form.minutes,
            until_close: form.until_close,
            reason,
        });
        if (ok) setOpen(false);
    };

    const resume = (pauseId) => post('/wms/pickups/desk/resume', { pause_id: pauseId ?? null });

    return (
        <Box p="3" bg={style.bg} borderRadius="lg" borderWidth="1px" borderColor={style.border}>
            <HStack justify="space-between" align="flex-start" gap="3" flexWrap="wrap">
                <Box minW="0" flex="1">
                    <HStack gap="2" color={style.color}>
                        <LuDoorOpen />
                        <Text fontWeight="800" fontSize="lg">{desk.text}</Text>
                    </HStack>
                    <Text fontSize="sm" color="fg.muted">
                        {desk.today_text}
                        {desk.state === 'open' && desk.next_break ? `. Следующий перерыв ${desk.next_break}` : ''}
                    </Text>
                    {roster.length > 0 && (
                        <Text fontSize="xs" color="fg.muted" mt="1">
                            На смене: {roster.map((s) => s.name).join(', ')}
                            {roster.length > 1 ? ' — перерыв одного выдачу не закрывает' : ''}
                        </Text>
                    )}
                    {pauses.map((p) => (
                        <HStack key={p.id} fontSize="sm" mt="1" gap="2" flexWrap="wrap">
                            <Text>
                                {p.staff_name ? `${p.staff_name}: ${p.reason}` : `Стойка закрыта: ${p.reason}`} до {p.until}
                            </Text>
                            {canIssue && <Button size="xs" variant="outline" loading={busy} onClick={() => resume(p.id)}><LuUndo2 /> Вернулся</Button>}
                        </HStack>
                    ))}
                </Box>
                <HStack gap="2">
                    {canIssue && desk.state !== 'closed' && (
                        <Button size="sm" variant={desk.state === 'open' ? 'solid' : 'outline'} colorPalette="orange" onClick={openDialog}>
                            <LuCoffee /> Отойти
                        </Button>
                    )}
                    {can('wms-pickups.schedule') && (
                        <Button asChild size="sm" variant="ghost"><Link href="/wms/pickups/schedule"><LuCalendarClock /> График</Link></Button>
                    )}
                </HStack>
            </HStack>

            <Dialog.Root open={open} onOpenChange={(e) => { if (!e.open) setOpen(false); }} size="sm" placement="center">
                <Portal>
                    <Dialog.Backdrop />
                    <Dialog.Positioner>
                        <Dialog.Content>
                            <Dialog.Header><Dialog.Title>Отойти со стойки</Dialog.Title></Dialog.Header>
                            <Dialog.Body>
                                <VStack align="stretch" gap="4">
                                    {roster.length > 1 && (
                                        <Field label="Кто отходит">
                                            <HStack gap="2" flexWrap="wrap">
                                                {roster.map((s) => (
                                                    <Button key={s.id} size="sm" variant={String(s.id) === form.staff_id ? 'solid' : 'outline'}
                                                        onClick={() => setForm((f) => ({ ...f, staff_id: String(s.id) }))}>{s.name}</Button>
                                                ))}
                                            </HStack>
                                        </Field>
                                    )}
                                    {roster.length === 0 && (
                                        <Text fontSize="sm" color="fg.muted">Смена на сегодня не заведена — отлучка закроет стойку целиком.</Text>
                                    )}
                                    <Field label="На сколько">
                                        <HStack gap="2" flexWrap="wrap">
                                            {DURATIONS.map((m) => (
                                                <Button key={m} size="sm" variant={!form.until_close && form.minutes === m ? 'solid' : 'outline'}
                                                    onClick={() => setForm((f) => ({ ...f, minutes: m, until_close: false }))}>{m} мин</Button>
                                            ))}
                                            <Button size="sm" variant={form.until_close ? 'solid' : 'outline'}
                                                onClick={() => setForm((f) => ({ ...f, until_close: true }))}>До конца дня</Button>
                                        </HStack>
                                    </Field>
                                    <Field label="Причина — её увидят курьер и клиент">
                                        <HStack gap="2" flexWrap="wrap">
                                            {REASONS.map((r) => (
                                                <Button key={r} size="sm" variant={form.reason === r ? 'solid' : 'outline'}
                                                    onClick={() => setForm((f) => ({ ...f, reason: r }))}>{r}</Button>
                                            ))}
                                        </HStack>
                                        {form.reason === 'другое' && (
                                            <Input mt="2" placeholder="Коротко, до 60 знаков" maxLength={60} value={form.custom}
                                                onChange={(e) => setForm((f) => ({ ...f, custom: e.target.value }))} />
                                        )}
                                    </Field>
                                </VStack>
                            </Dialog.Body>
                            <Dialog.Footer>
                                <Button variant="ghost" onClick={() => setOpen(false)}>Отмена</Button>
                                <Button colorPalette="orange" loading={busy} onClick={submitPause}>Отойти</Button>
                            </Dialog.Footer>
                        </Dialog.Content>
                    </Dialog.Positioner>
                </Portal>
            </Dialog.Root>
        </Box>
    );
}
