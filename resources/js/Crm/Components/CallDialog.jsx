import { useEffect, useState } from 'react';
import axios from 'axios';
import {
    Box,
    Dialog,
    HStack,
    Input,
    Portal,
    SimpleGrid,
    Text,
    VStack,
} from '@chakra-ui/react';
import { LuCopy, LuPhone } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field } from '@/components/ui/field';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';
import { SegmentedControl } from '@/components/ui/segmented-control';
import VoiceInput from '@/shared/voice/VoiceInput';
import VoiceTextarea from '@/shared/voice/VoiceTextarea';
import { toastError, toastSuccess } from '@/utils/toast';

const EMPTY = {
    direction: 'outgoing',
    result: 'talked',
    contact_name: '',
    summary: '',
    duration_sec: '',
};

const FOLLOW_UP_EMPTY = { title: '', due_at: '', priority: 'normal' };

/**
 * Копирование номера.
 *
 * navigator.clipboard живёт только в защищённом контексте, а CRM открывают
 * и по http внутри сети — поэтому фолбэк, а не «кнопка не работает».
 */
async function copyText(text) {
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
        } else {
            const area = document.createElement('textarea');
            area.value = text;
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            document.execCommand('copy');
            document.body.removeChild(area);
        }
        toastSuccess('Номер скопирован');
    } catch {
        toastError('Не удалось скопировать номер');
    }
}

/**
 * Звонок клиенту: набрать и записать, чем закончилось.
 *
 * Телефония не подключена — набор идёт через tel:-ссылку операционной системы,
 * а диалог фиксирует результат. Когда появится АТС, тот же диалог начнёт получать
 * длительность и запись разговора из вебхука, а форма останется прежней.
 *
 * Чекбокс «Поставить следующую задачу» — не украшение: звонок это ровно тот момент,
 * когда о клиенте думают в последний раз, и если сейчас не назначить следующий шаг,
 * клиент выпадет из работы до своего входящего.
 *
 * @param {boolean} open
 * @param {{id: number, name: string, phone: string|null, phone_digits: string|null}|null} client
 * @param {Function} onSaved — колбэк с записанным звонком
 */
export default function CallDialog({ open, client, onClose, onSaved }) {
    const [form, setForm] = useState(EMPTY);
    const [withFollowUp, setWithFollowUp] = useState(false);
    const [followUp, setFollowUp] = useState(FOLLOW_UP_EMPTY);
    const [options, setOptions] = useState(null);
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (!open) {
            return;
        }

        setForm(EMPTY);
        setFollowUp(FOLLOW_UP_EMPTY);
        setWithFollowUp(false);
        setErrors({});

        if (!options) {
            axios.get(route('crm.calls.options'))
                .then((res) => setOptions(res.data))
                .catch(() => setOptions(null));
        }
    }, [open, options]);

    const set = (field, value) => setForm((prev) => ({ ...prev, [field]: value }));
    const error = (field) => errors[field]?.[0];

    const save = async () => {
        setBusy(true);
        setErrors({});

        try {
            const payload = {
                entity_type: 'client',
                entity_id: client.id,
                direction: form.direction,
                result: form.result,
                phone: client.phone || null,
                contact_name: form.contact_name || null,
                summary: form.summary || null,
                duration_sec: form.duration_sec === '' ? null : Number(form.duration_sec),
            };

            if (withFollowUp && followUp.title.trim()) {
                payload.follow_up = {
                    title: followUp.title.trim(),
                    due_at: followUp.due_at || null,
                    priority: followUp.priority,
                };
            }

            const { data } = await axios.post(route('crm.calls.store'), payload);
            toastSuccess(payload.follow_up ? 'Звонок записан, следующий шаг поставлен' : 'Звонок записан');
            onSaved?.(data);
            onClose();
        } catch (e) {
            if (e?.response?.status === 422) {
                setErrors(e.response.data.errors || {});
            } else {
                toastError('Звонок не записан', e?.response?.data?.message || 'Попробуйте ещё раз.');
            }
        } finally {
            setBusy(false);
        }
    };

    if (!client) return null;

    const telHref = `tel:+${client.phone_digits || (client.phone || '').replace(/\D+/g, '')}`;

    return (
        <Dialog.Root open={open} onOpenChange={(e) => { if (!e.open) onClose(); }} size="lg">
            <Portal>
                <Dialog.Backdrop />
                <Dialog.Positioner>
                    <Dialog.Content>
                        <Dialog.Header>
                            <Dialog.Title>Звонок: {client.name}</Dialog.Title>
                        </Dialog.Header>

                        <Dialog.Body>
                            <VStack align="stretch" gap={4}>
                                {client.phone ? (
                                    <HStack
                                        justify="space-between"
                                        borderWidth="1px"
                                        borderRadius="md"
                                        px={3}
                                        py={2}
                                        bg="bg.subtle"
                                    >
                                        <Text fontSize="xl" fontWeight="600" fontFamily="mono">{client.phone}</Text>
                                        <HStack gap={2}>
                                            <Button size="sm" asChild>
                                                <a href={telHref}><LuPhone /> Позвонить</a>
                                            </Button>
                                            <Button size="sm" variant="outline" onClick={() => copyText(client.phone)}>
                                                <LuCopy /> Скопировать
                                            </Button>
                                        </HStack>
                                    </HStack>
                                ) : (
                                    <Text fontSize="sm" color="fg.muted">
                                        У клиента не указан телефон — запишите звонок вручную.
                                    </Text>
                                )}

                                <SimpleGrid columns={{ base: 1, md: 2 }} gap={3}>
                                    <Field label="Направление">
                                        <SegmentedControl
                                            size="sm"
                                            value={form.direction}
                                            onValueChange={(e) => set('direction', e.value)}
                                            items={(options?.directions || [
                                                { value: 'outgoing', label: 'Исходящий' },
                                                { value: 'incoming', label: 'Входящий' },
                                            ]).map((item) => ({ value: item.value, label: item.label }))}
                                        />
                                    </Field>

                                    <Field label="Итог" errorText={error('result')} invalid={!!error('result')}>
                                        <NativeSelectRoot size="sm">
                                            <NativeSelectField
                                                value={form.result}
                                                onChange={(e) => set('result', e.target.value)}
                                            >
                                                {(options?.results || [{ value: 'talked', label: 'Поговорили' }]).map((item) => (
                                                    <option key={item.value} value={item.value}>{item.label}</option>
                                                ))}
                                            </NativeSelectField>
                                        </NativeSelectRoot>
                                    </Field>

                                    <Field label="С кем говорили" errorText={error('contact_name')} invalid={!!error('contact_name')}>
                                        <Input
                                            size="sm"
                                            value={form.contact_name}
                                            onChange={(e) => set('contact_name', e.target.value)}
                                            placeholder="Если не основное контактное лицо"
                                        />
                                    </Field>

                                    <Field
                                        label="Длительность, секунд"
                                        errorText={error('duration_sec')}
                                        invalid={!!error('duration_sec')}
                                        helperText="Необязательно — заполнится автоматически, когда подключим АТС"
                                    >
                                        <Input
                                            size="sm"
                                            type="number"
                                            min={0}
                                            value={form.duration_sec}
                                            onChange={(e) => set('duration_sec', e.target.value)}
                                        />
                                    </Field>
                                </SimpleGrid>

                                <Field label="О чём договорились" errorText={error('summary')} invalid={!!error('summary')}>
                                    <VoiceTextarea
                                        value={form.summary}
                                        onChange={(value) => set('summary', value)}
                                        rows={3}
                                        placeholder="Коротко: что решили, что обещали, когда вернуться"
                                    />
                                </Field>

                                <Box borderTopWidth="1px" pt={3}>
                                    <Checkbox
                                        checked={withFollowUp}
                                        onCheckedChange={(e) => setWithFollowUp(!!e.checked)}
                                    >
                                        Поставить следующую задачу
                                    </Checkbox>

                                    {withFollowUp && (
                                        <SimpleGrid columns={{ base: 1, md: 2 }} gap={3} mt={3}>
                                            <Field
                                                label="Что сделать"
                                                errorText={error('follow_up.title')}
                                                invalid={!!error('follow_up.title')}
                                            >
                                                <VoiceInput
                                                    size="sm"
                                                    value={followUp.title}
                                                    onChange={(value) => setFollowUp((p) => ({ ...p, title: value }))}
                                                    placeholder="Например: выставить счёт"
                                                    title="Надиктовать следующий шаг"
                                                />
                                            </Field>

                                            <Field label="Срок" errorText={error('follow_up.due_at')} invalid={!!error('follow_up.due_at')}>
                                                <Input
                                                    size="sm"
                                                    type="datetime-local"
                                                    value={followUp.due_at}
                                                    onChange={(e) => setFollowUp((p) => ({ ...p, due_at: e.target.value }))}
                                                />
                                            </Field>
                                        </SimpleGrid>
                                    )}
                                </Box>
                            </VStack>
                        </Dialog.Body>

                        <Dialog.Footer>
                            <Button variant="ghost" onClick={onClose} disabled={busy}>Отмена</Button>
                            <Button onClick={save} loading={busy}>Записать звонок</Button>
                        </Dialog.Footer>
                    </Dialog.Content>
                </Dialog.Positioner>
            </Portal>
        </Dialog.Root>
    );
}
