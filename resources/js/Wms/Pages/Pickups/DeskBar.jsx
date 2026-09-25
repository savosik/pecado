import { useEffect, useState } from 'react';
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

const mmss = (seconds) => {
    const s = Math.max(0, Math.floor(seconds));
    const h = Math.floor(s / 3600); const m = Math.floor((s % 3600) / 60); const sec = s % 60;
    const pad = (n) => String(n).padStart(2, '0');
    return h > 0 ? `${h}:${pad(m)}:${pad(sec)}` : `${pad(m)}:${pad(sec)}`;
};

/** Секундомер отлучки: тикает от момента «Отойти» до «Вернулся» — видно, сколько стойка стоит. */
function Elapsed({ since }) {
    const [now, setNow] = useState(Date.now());
    useEffect(() => { const t = setInterval(() => setNow(Date.now()), 1000); return () => clearInterval(t); }, []);
    return <Text as="span" fontVariantNumeric="tabular-nums" fontWeight="700">{mmss((now - new Date(since).getTime()) / 1000)}</Text>;
}

/**
 * Стойка выдачи на экране склада (pick-18): одна компактная полоса — экран нужен для выдачи, не для неё.
 *
 * Открыто: «Сейчас выдают» + перерывы сегодня + «Отойти». Отошёл: «Перерыв до …» + тикающий секундомер
 * + «Вернулся». То же в этот момент видят курьер в пропуске и клиент в кабинете.
 */
export default function DeskBar({ desk, canIssue, onChange }) {
    const { can } = usePermission();
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [form, setForm] = useState({ minutes: 20, until_close: false, reason: 'обед', custom: '' });

    if (!desk) return null;
    const style = STATE_STYLE[desk.state] || STATE_STYLE.closed;
    const pause = (desk.pauses || [])[0] || null;

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
        const ok = await post('/wms/pickups/desk/pause', { minutes: form.until_close ? null : form.minutes, until_close: form.until_close, reason });
        if (ok) setOpen(false);
    };

    return (
        <Box px="3" py="2" bg={style.bg} borderRadius="lg" borderWidth="1px" borderColor={style.border}>
            <HStack justify="space-between" align="center" gap="2">
                <Box minW="0" flex="1">
                    <HStack gap="2" color={style.color} align="center">
                        <LuDoorOpen />
                        <Text fontWeight="800" lineClamp="1">{desk.text}</Text>
                    </HStack>
                    <Text fontSize="xs" color="fg.muted" lineClamp="1">
                        {pause
                            ? <>Отошёл{pause.user_name ? ` ${pause.user_name}` : ''} · {pause.reason} · уже <Elapsed since={pause.started_at} /></>
                            : <>{desk.today_text}{desk.state === 'open' && desk.next_break ? `. Следующий ${desk.next_break}` : ''}</>}
                    </Text>
                </Box>
                <HStack gap="1" flexShrink={0}>
                    {canIssue && pause && (
                        <Button size="sm" colorPalette="green" loading={busy} onClick={() => post('/wms/pickups/desk/resume', {})}><LuUndo2 /> Вернулся</Button>
                    )}
                    {canIssue && !pause && desk.state !== 'closed' && (
                        <Button size="sm" variant="outline" colorPalette="orange" onClick={() => { setForm({ minutes: 20, until_close: false, reason: 'обед', custom: '' }); setOpen(true); }}><LuCoffee /> Отойти</Button>
                    )}
                    {can('wms-pickups.schedule') && (
                        <Button asChild size="sm" variant="ghost" aria-label="График выдачи"><Link href="/wms/pickups/schedule"><LuCalendarClock /></Link></Button>
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
