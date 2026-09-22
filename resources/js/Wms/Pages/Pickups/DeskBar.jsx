import { useState } from 'react';
import { Link } from '@inertiajs/react';
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
    const { can } = usePermission();
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [form, setForm] = useState({ minutes: 20, until_close: false, reason: 'обед', custom: '' });

    if (!desk) return null;
    const style = STATE_STYLE[desk.state] || STATE_STYLE.closed;
    const pauses = desk.pauses || [];

    const openDialog = () => {
        setForm({ minutes: 20, until_close: false, reason: 'обед', custom: '' });
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
        const ok = await post('/wms/pickups/desk/pause', {
            minutes: form.until_close ? null : form.minutes,
            until_close: form.until_close,
            reason,
        });
        if (ok) setOpen(false);
    };

    const resume = () => post('/wms/pickups/desk/resume', {});

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
                    {pauses.map((p) => (
                        <HStack key={p.id} fontSize="sm" mt="1" gap="2" flexWrap="wrap">
                            <Text>Отошёл{p.user_name ? ` ${p.user_name}` : ''}: {p.reason}, до {p.until}</Text>
                            {canIssue && <Button size="xs" variant="outline" loading={busy} onClick={resume}><LuUndo2 /> Вернулся</Button>}
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
                                    <Text fontSize="sm" color="fg.muted">Курьер и клиент увидят «Перерыв до …» и не поедут впустую. Вернулись раньше — нажмите «Вернулся».</Text>
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
