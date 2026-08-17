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
import VoiceInput from '@/shared/voice/VoiceInput';
import VoiceTextarea from '@/shared/voice/VoiceTextarea';
import { useTaskOptions } from '@/Crm/Components/useTaskOptions';
import { toastError, toastSuccess } from '@/utils/toast';

// Три исхода. «Перенести» — не закрытие: задача остаётся открытой,
// сдвигается срок и растёт счётчик переносов.
const MODES = [
    { value: 'success', label: 'Завершить успешно', color: 'green' },
    { value: 'problem', label: 'Завершить с проблемой', color: 'orange' },
    { value: 'postpone', label: 'Перенести', color: 'blue' },
];

/**
 * Закрытие задачи: исход, что сделано и что дальше.
 *
 * Момент закрытия — единственный, когда менеджер точно думает об этом партнёре.
 * Если не спросить «следующий шаг» здесь, партнёр выпадет из работы до тех пор,
 * пока не позвонит сам, — а «покрытие задачами» держится именно на этой привычке.
 *
 * При «успешно» отчёт и follow-up необязательны; при «проблеме» отчёт обязателен,
 * а галочка следующего шага включена по умолчанию — проблема почти всегда
 * требует продолжения. Скипнуть можно и там и там.
 */
export default function TaskCloseDialog({ task, onClose, onClosed }) {
    const open = !!task;
    const options = useTaskOptions(open);
    const [mode, setMode] = useState('success');
    const [comment, setComment] = useState('');
    const [withFollowUp, setWithFollowUp] = useState(false);
    const [followUp, setFollowUp] = useState({ title: '', due_at: '', priority: 'normal', assignee_id: '' });
    const [postponeDue, setPostponeDue] = useState('');
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (!open) {
            return;
        }

        setMode('success');
        setComment('');
        setWithFollowUp(false);
        setErrors({});
        setPostponeDue(task.due_at || defaultDue(1));
        setFollowUp({
            title: '',
            // Неделя — обычный горизонт следующего касания; дату всегда можно поправить.
            due_at: defaultDue(7),
            priority: task.priority || 'normal',
            assignee_id: '',
        });
    }, [open, task]);

    // Проблема почти всегда требует следующего шага — галочка включается сама,
    // но остаётся снимаемой.
    const selectMode = (value) => {
        setMode(value);
        setErrors({});

        if (value === 'problem') {
            setWithFollowUp(true);
        }
    };

    // Следующий шаг остаётся за исполнителем закрываемой задачи — это продолжение
    // его же работы с партнёром. Подставляем только когда такой человек есть в
    // справочнике: иначе селект показал бы пустоту при непустом значении, а бэкенд
    // и без подсказки унаследует исполнителя закрываемой задачи.
    const assigneeId = task?.assignee?.id ?? null;

    useEffect(() => {
        if (!open || !assigneeId || !options?.assignees) {
            return;
        }

        if (!options.assignees.some((user) => Number(user.id) === Number(assigneeId))) {
            return;
        }

        setFollowUp((prev) => (prev.assignee_id ? prev : { ...prev, assignee_id: String(assigneeId) }));
    }, [open, assigneeId, options]);

    const submit = async () => {
        setBusy(true);
        setErrors({});

        try {
            if (mode === 'postpone') {
                const res = await axios.post(`/crm/tasks/${task.id}/postpone`, {
                    due_at: postponeDue,
                    reason: comment.trim() || null,
                });

                toastSuccess('Срок перенесён', res.data.due_at_label ? `Новый срок: ${res.data.due_at_label}` : undefined);
                onClosed?.(res.data, null);
                onClose();

                return;
            }

            const payload = {
                outcome: mode,
                comment: comment.trim() || null,
            };

            if (withFollowUp) {
                payload.follow_up = {
                    title: followUp.title,
                    due_at: followUp.due_at || null,
                    priority: followUp.priority,
                    // Пусто — бэкенд сам возьмёт исполнителя закрываемой задачи.
                    assignee_id: followUp.assignee_id || null,
                };
            }

            const res = await axios.post(`/crm/tasks/${task.id}/close`, payload);

            toastSuccess(
                mode === 'problem' ? 'Задача закрыта с проблемой' : 'Задача закрыта',
                res.data.follow_up ? `Следующий шаг: ${res.data.follow_up.title}` : undefined,
            );
            onClosed?.(res.data.task, res.data.follow_up);
            onClose();
        } catch (e) {
            if (e?.response?.status === 422) {
                setErrors(e.response.data.errors || {});
            } else {
                toastError('Не получилось', e?.response?.data?.message || 'Попробуйте ещё раз.');
            }
        } finally {
            setBusy(false);
        }
    };

    const error = (field) => errors[field]?.[0];
    const activeMode = MODES.find((item) => item.value === mode);
    const checklistLeft = (task?.checklist_total || 0) - (task?.checklist_done || 0);

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
                            <Dialog.Title>Что с задачей?</Dialog.Title>
                        </Dialog.Header>

                        <Dialog.Body>
                            <VStack align="stretch" gap={4}>
                                <Text fontSize="sm" fontWeight="600">{task?.title}</Text>

                                {checklistLeft > 0 && mode !== 'postpone' && (
                                    <Text fontSize="xs" color="orange.fg">
                                        В чек-листе остались невыполненные пункты
                                        ({task.checklist_done}/{task.checklist_total}) — закрыть всё равно можно.
                                    </Text>
                                )}

                                <HStack gap={2} flexWrap="wrap">
                                    {MODES.map((item) => (
                                        <Button
                                            key={item.value}
                                            size="sm"
                                            variant={mode === item.value ? 'solid' : 'outline'}
                                            colorPalette={mode === item.value ? item.color : undefined}
                                            onClick={() => selectMode(item.value)}
                                        >
                                            {item.label}
                                        </Button>
                                    ))}
                                </HStack>

                                {mode === 'postpone' && (
                                    <VStack align="stretch" gap={3}>
                                        <HStack gap={2} flexWrap="wrap">
                                            <Button size="xs" variant="outline" onClick={() => setPostponeDue(defaultDue(1))}>
                                                Завтра
                                            </Button>
                                            <Button size="xs" variant="outline" onClick={() => setPostponeDue(defaultDue(3))}>
                                                +3 дня
                                            </Button>
                                            <Button size="xs" variant="outline" onClick={() => setPostponeDue(nextMonday())}>
                                                Следующий понедельник
                                            </Button>
                                        </HStack>

                                        <Field
                                            label="Новый срок"
                                            required
                                            errorText={error('due_at')}
                                            invalid={!!error('due_at')}
                                        >
                                            <Input
                                                type="datetime-local"
                                                value={postponeDue}
                                                onChange={(e) => setPostponeDue(e.target.value)}
                                            />
                                        </Field>

                                        {(task?.postponed_count || 0) > 0 && (
                                            <Text fontSize="xs" color="fg.muted">
                                                Задача уже переносилась: {task.postponed_count} раз(а).
                                            </Text>
                                        )}
                                    </VStack>
                                )}

                                <Field
                                    label={mode === 'postpone' ? 'Причина переноса' : 'Что сделано'}
                                    required={mode === 'problem'}
                                    helperText={mode === 'problem'
                                        ? 'Обязательно: опишите, что пошло не так.'
                                        : 'Необязательно. Комментарий останется в ленте партнёра.'}
                                    errorText={error('comment')}
                                    invalid={!!error('comment')}
                                >
                                    <VoiceTextarea
                                        value={comment}
                                        onChange={setComment}
                                        rows={3}
                                        placeholder={mode === 'problem'
                                            ? 'Партнёр отказался: не устроила цена доставки'
                                            : 'Договорились о поставке на следующей неделе'}
                                        autoFocus
                                    />
                                </Field>

                                {mode !== 'postpone' && (
                                    <>
                                        <Box borderTopWidth="1px" pt={3}>
                                            <Checkbox
                                                checked={withFollowUp}
                                                onCheckedChange={(e) => setWithFollowUp(!!e.checked)}
                                            >
                                                Поставить следующую задачу по этому партнёру
                                            </Checkbox>
                                            <Text fontSize="xs" color="fg.muted" mt={1}>
                                                Партнёр без следующего шага выпадает из работы до тех пор, пока не позвонит сам.
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
                                                    <VoiceInput
                                                        value={followUp.title}
                                                        onChange={(value) => setFollowUp((prev) => ({ ...prev, title: value }))}
                                                        placeholder="Например: позвонить и уточнить объём"
                                                        title="Надиктовать следующий шаг"
                                                    />
                                                </Field>

                                                <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                                    <Field
                                                        label="Исполнитель"
                                                        errorText={error('follow_up.assignee_id')}
                                                        invalid={!!error('follow_up.assignee_id')}
                                                    >
                                                        <NativeSelectRoot>
                                                            <NativeSelectField
                                                                value={followUp.assignee_id}
                                                                onChange={(e) => setFollowUp((prev) => ({ ...prev, assignee_id: e.target.value }))}
                                                            >
                                                                <option value="">Как в текущей задаче</option>
                                                                {(options?.assignees || []).map((user) => (
                                                                    <option key={user.id} value={user.id}>{user.name}</option>
                                                                ))}
                                                            </NativeSelectField>
                                                        </NativeSelectRoot>
                                                    </Field>

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
                                                    Привязка унаследуется от закрываемой задачи.
                                                </Text>
                                            </VStack>
                                        )}
                                    </>
                                )}
                            </VStack>
                        </Dialog.Body>

                        <Dialog.Footer>
                            <HStack gap={2}>
                                <Button variant="outline" onClick={onClose} disabled={busy}>Отмена</Button>
                                <Button colorPalette={activeMode?.color} onClick={submit} loading={busy}>
                                    {activeMode?.label}
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
 * Срок через N дней в формате datetime-local, минуты обнулены.
 */
function defaultDue(days) {
    const date = new Date();
    date.setDate(date.getDate() + days);
    date.setMinutes(0, 0, 0);

    return toLocalInput(date);
}

function nextMonday() {
    const date = new Date();
    const day = date.getDay() || 7;
    date.setDate(date.getDate() + (8 - day));
    date.setHours(10, 0, 0, 0);

    return toLocalInput(date);
}

function toLocalInput(date) {
    const pad = (value) => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}
