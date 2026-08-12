import { useEffect, useState } from 'react';
import axios from 'axios';
import { Box, Dialog, HStack, Input, Portal, Text, Textarea, VStack } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Checkbox } from '@/components/ui/checkbox';
import { useTaskOptions } from '@/Crm/Components/useTaskOptions';
import { toastError, toastSuccess } from '@/utils/toast';

const WEEKDAYS = [
    { value: 1, label: 'Пн' },
    { value: 2, label: 'Вт' },
    { value: 3, label: 'Ср' },
    { value: 4, label: 'Чт' },
    { value: 5, label: 'Пт' },
    { value: 6, label: 'Сб' },
    { value: 7, label: 'Вс' },
];

const WORKDAYS = [1, 2, 3, 4, 5];

const today = () => new Date().toISOString().slice(0, 10);

const EMPTY = {
    title: '',
    description: '',
    assignee_id: '',
    weekdays: WORKDAYS,
    due_time: '10:00',
    starts_on: today(),
    ends_on: '',
};

/**
 * Правило автоповтора: «каждый будний день в 13:30».
 *
 * Отдельный диалог, а не режим задачи: правило — это станок, а не поручение.
 * Смешивать их в одной форме значит заставлять менеджера каждый раз решать,
 * что он сейчас заводит.
 */
export default function TaskRecurrenceDialog({ open, onClose, onSaved }) {
    const options = useTaskOptions(open);
    const [form, setForm] = useState(EMPTY);
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (open) {
            setForm(EMPTY);
            setErrors({});
        }
    }, [open]);

    const set = (patch) => setForm((prev) => ({ ...prev, ...patch }));

    const toggleDay = (day) => set({
        weekdays: form.weekdays.includes(day)
            ? form.weekdays.filter((item) => item !== day)
            : [...form.weekdays, day].sort((a, b) => a - b),
    });

    const submit = async () => {
        setBusy(true);
        setErrors({});

        try {
            await axios.post(route('crm.task-recurrences.store'), {
                ...form,
                ends_on: form.ends_on || null,
            });

            toastSuccess('Правило создано. Задачи будут появляться по расписанию.');
            onSaved?.();
            onClose();
        } catch (error) {
            if (error.response?.status === 422) {
                setErrors(error.response.data.errors ?? {});
            } else {
                toastError('Не удалось создать правило.');
            }
        } finally {
            setBusy(false);
        }
    };

    const firstError = (key) => errors[key]?.[0];

    return (
        <Dialog.Root
            open={open}
            onOpenChange={({ open: isOpen }) => ! isOpen && onClose()}
            size="lg"
            scrollBehavior="inside"
            closeOnInteractOutside={false}
        >
            <Portal>
                <Dialog.Backdrop />
                <Dialog.Positioner>
                    <Dialog.Content>
                        <Dialog.Header>
                            <Dialog.Title>Повторяющаяся задача</Dialog.Title>
                        </Dialog.Header>

                        <Dialog.Body>
                            <VStack align="stretch" gap={4}>
                                <Field label="Что делать" required invalid={!! firstError('title')} errorText={firstError('title')}>
                                    <Input
                                        value={form.title}
                                        onChange={(event) => set({ title: event.target.value })}
                                        placeholder="Например: обзвонить спящих клиентов"
                                    />
                                </Field>

                                <Field label="Подробности">
                                    <Textarea
                                        rows={3}
                                        value={form.description}
                                        onChange={(event) => set({ description: event.target.value })}
                                    />
                                </Field>

                                <Field label="Исполнитель" required invalid={!! firstError('assignee_id')} errorText={firstError('assignee_id')}>
                                    <select
                                        value={form.assignee_id}
                                        onChange={(event) => set({ assignee_id: event.target.value })}
                                        style={{
                                            padding: '0.4rem',
                                            borderRadius: '0.375rem',
                                            border: '1px solid var(--chakra-colors-border)',
                                            width: '100%',
                                        }}
                                    >
                                        <option value="">Выберите исполнителя</option>
                                        {(options?.assignees ?? []).map((user) => (
                                            <option key={user.id} value={user.id}>{user.name}</option>
                                        ))}
                                    </select>
                                </Field>

                                <Field label="Дни недели" required invalid={!! firstError('weekdays')} errorText={firstError('weekdays')}>
                                    <VStack align="stretch" gap={2}>
                                        <HStack gap={3} wrap="wrap">
                                            {WEEKDAYS.map((day) => (
                                                <Checkbox
                                                    key={day.value}
                                                    size="sm"
                                                    checked={form.weekdays.includes(day.value)}
                                                    onCheckedChange={() => toggleDay(day.value)}
                                                >
                                                    {day.label}
                                                </Checkbox>
                                            ))}
                                        </HStack>
                                        <Box>
                                            <Button size="xs" variant="outline" onClick={() => set({ weekdays: WORKDAYS })}>
                                                Каждый будний день
                                            </Button>
                                        </Box>
                                    </VStack>
                                </Field>

                                <HStack gap={4} align="start">
                                    <Field label="Время" required invalid={!! firstError('due_time')} errorText={firstError('due_time')}>
                                        <Input
                                            type="time"
                                            value={form.due_time}
                                            onChange={(event) => set({ due_time: event.target.value })}
                                        />
                                    </Field>

                                    <Field label="Начать с" required invalid={!! firstError('starts_on')} errorText={firstError('starts_on')}>
                                        <Input
                                            type="date"
                                            value={form.starts_on}
                                            onChange={(event) => set({ starts_on: event.target.value })}
                                        />
                                    </Field>

                                    <Field label="Закончить" invalid={!! firstError('ends_on')} errorText={firstError('ends_on')}>
                                        <Input
                                            type="date"
                                            value={form.ends_on}
                                            onChange={(event) => set({ ends_on: event.target.value })}
                                        />
                                    </Field>
                                </HStack>

                                <Text fontSize="xs" color="fg.muted">
                                    Пустая дата окончания — цепочка живёт, пока её не отменят. Отмена
                                    останавливает создание новых задач, уже созданные остаются в истории.
                                </Text>
                            </VStack>
                        </Dialog.Body>

                        <Dialog.Footer>
                            <Button variant="ghost" onClick={onClose} disabled={busy}>Отмена</Button>
                            <Button onClick={submit} loading={busy}>Создать правило</Button>
                        </Dialog.Footer>
                    </Dialog.Content>
                </Dialog.Positioner>
            </Portal>
        </Dialog.Root>
    );
}
