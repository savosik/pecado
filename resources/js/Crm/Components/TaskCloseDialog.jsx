import { useEffect, useState } from 'react';
import axios from 'axios';
import {
    Box,
    Dialog,
    HStack,
    Input,
    NativeSelectField,
    NativeSelectRoot,
    Portal,
    SimpleGrid,
    Text,
    VStack,
} from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field } from '@/components/ui/field';
import VoiceTextarea from '@/shared/voice/VoiceTextarea';
import { useTaskOptions } from '@/Crm/Components/useTaskOptions';
import { toastError, toastSuccess } from '@/utils/toast';

/**
 * Закрытие задачи: что сделано и что дальше.
 *
 * Момент закрытия — единственный, когда менеджер точно думает об этом клиенте.
 * Если не спросить «следующий шаг» здесь, клиент выпадет из работы до тех пор,
 * пока не позвонит сам, — а «покрытие задачами» держится именно на этой привычке.
 *
 * Оба поля необязательны: требовать отчёт по каждой мелочи — верный способ
 * научить людей не закрывать задачи вовсе.
 */
export default function TaskCloseDialog({ task, onClose, onClosed }) {
    const open = !!task;
    const options = useTaskOptions(open);
    const [comment, setComment] = useState('');
    const [withFollowUp, setWithFollowUp] = useState(false);
    const [followUp, setFollowUp] = useState({ title: '', due_at: '', priority: 'normal' });
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (!open) {
            return;
        }

        setComment('');
        setWithFollowUp(false);
        setErrors({});
        setFollowUp({
            title: '',
            // Неделя — обычный горизонт следующего касания; дату всегда можно поправить.
            due_at: defaultDue(),
            priority: task.priority || 'normal',
        });
    }, [open, task]);

    const submit = async () => {
        setBusy(true);
        setErrors({});

        try {
            const payload = { comment: comment.trim() || null };

            if (withFollowUp) {
                payload.follow_up = {
                    title: followUp.title,
                    due_at: followUp.due_at || null,
                    priority: followUp.priority,
                };
            }

            const res = await axios.post(`/crm/tasks/${task.id}/close`, payload);

            toastSuccess(
                'Задача закрыта',
                res.data.follow_up ? `Следующий шаг: ${res.data.follow_up.title}` : undefined,
            );
            onClosed?.(res.data.task, res.data.follow_up);
            onClose();
        } catch (e) {
            if (e?.response?.status === 422) {
                setErrors(e.response.data.errors || {});
            } else {
                toastError('Задача не закрыта', e?.response?.data?.message || 'Попробуйте ещё раз.');
            }
        } finally {
            setBusy(false);
        }
    };

    const error = (field) => errors[field]?.[0];

    return (
        <Dialog.Root
            open={open}
            onOpenChange={({ open: isOpen }) => !isOpen && onClose()}
            size="lg"
            scrollBehavior="inside"
        >
            <Portal>
                <Dialog.Backdrop />
                <Dialog.Positioner>
                    <Dialog.Content>
                        <Dialog.Header>
                            <Dialog.Title>Задача выполнена</Dialog.Title>
                        </Dialog.Header>

                        <Dialog.Body>
                            <VStack align="stretch" gap={4}>
                                <Text fontSize="sm" fontWeight="600">{task?.title}</Text>

                                <Field
                                    label="Что сделано"
                                    helperText="Необязательно. Комментарий останется в ленте клиента."
                                    errorText={error('comment')}
                                    invalid={!!error('comment')}
                                >
                                    <VoiceTextarea
                                        value={comment}
                                        onChange={setComment}
                                        rows={3}
                                        placeholder="Договорились о поставке на следующей неделе"
                                        autoFocus
                                    />
                                </Field>

                                <Box borderTopWidth="1px" pt={3}>
                                    <Checkbox
                                        checked={withFollowUp}
                                        onCheckedChange={(e) => setWithFollowUp(!!e.checked)}
                                    >
                                        Поставить следующую задачу по этому клиенту
                                    </Checkbox>
                                    <Text fontSize="xs" color="fg.muted" mt={1}>
                                        Клиент без следующего шага выпадает из работы до тех пор, пока не позвонит сам.
                                    </Text>
                                </Box>

                                {withFollowUp && (
                                    <VStack align="stretch" gap={4}>
                                        <Field
                                            label="Что сделать дальше"
                                            required
                                            errorText={error('follow_up.title')}
                                            invalid={!!error('follow_up.title')}
                                        >
                                            <Input
                                                value={followUp.title}
                                                onChange={(e) => setFollowUp((prev) => ({ ...prev, title: e.target.value }))}
                                                placeholder="Например: позвонить и уточнить объём"
                                            />
                                        </Field>

                                        <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                            <Field
                                                label="Срок"
                                                errorText={error('follow_up.due_at')}
                                                invalid={!!error('follow_up.due_at')}
                                            >
                                                <Input
                                                    type="datetime-local"
                                                    value={followUp.due_at}
                                                    onChange={(e) => setFollowUp((prev) => ({ ...prev, due_at: e.target.value }))}
                                                />
                                            </Field>

                                            <Field label="Приоритет">
                                                <NativeSelectRoot>
                                                    <NativeSelectField
                                                        value={followUp.priority}
                                                        onChange={(e) => setFollowUp((prev) => ({ ...prev, priority: e.target.value }))}
                                                    >
                                                        {(options?.priorities || []).map((item) => (
                                                            <option key={item.value} value={item.value}>{item.label}</option>
                                                        ))}
                                                    </NativeSelectField>
                                                </NativeSelectRoot>
                                            </Field>
                                        </SimpleGrid>

                                        <Text fontSize="xs" color="fg.muted">
                                            Привязка и исполнитель унаследуются от закрываемой задачи.
                                        </Text>
                                    </VStack>
                                )}
                            </VStack>
                        </Dialog.Body>

                        <Dialog.Footer>
                            <HStack gap={2}>
                                <Button variant="outline" onClick={onClose} disabled={busy}>Отмена</Button>
                                <Button colorPalette="green" onClick={submit} loading={busy}>
                                    Закрыть задачу
                                </Button>
                            </HStack>
                        </Dialog.Footer>
                    </Dialog.Content>
                </Dialog.Positioner>
            </Portal>
        </Dialog.Root>
    );
}

/**
 * Срок следующего шага по умолчанию — через неделю, в формате datetime-local.
 */
function defaultDue() {
    const date = new Date();
    date.setDate(date.getDate() + 7);
    date.setMinutes(0, 0, 0);

    const pad = (value) => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:00`;
}
