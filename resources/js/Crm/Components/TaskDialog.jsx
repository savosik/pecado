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
    Textarea,
    VStack,
} from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { useTaskOptions } from '@/Crm/Components/useTaskOptions';
import CommentThread from '@/Crm/Components/CommentThread';
import AttachmentPanel from '@/Crm/Components/AttachmentPanel';
import { usePermission } from '@/shared/Panel/usePermission';
import { toastError, toastSuccess } from '@/utils/toast';

const EMPTY = {
    title: '',
    description: '',
    assignee_id: '',
    status: 'open',
    priority: 'normal',
    due_at: '',
};

/**
 * Создание и правка задачи.
 *
 * Живёт в Components, а не в Pages/Tasks: тот же диалог открывается из карточки клиента
 * и из админских карточек заказа и реализации, где страницы задач нет вовсе.
 *
 * Комментарии и файлы показываются только у сохранённой задачи — `MediaService` не умеет
 * загружать «в никуда», поэтому новая задача создаётся первым шагом.
 *
 * @param {object|null} task — правим существующую или создаём новую
 * @param {{type: string, id: number}|null} entity — привязка для новой задачи
 * @param {Function} onSaved — колбэк с сохранённой задачей
 */
export default function TaskDialog({ open, onClose, task = null, entity = null, onSaved }) {
    const options = useTaskOptions(open);
    const { can } = usePermission();
    const [form, setForm] = useState(EMPTY);
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);

    const isEdit = !!task;
    const canEditFields = !isEdit || task.can?.update;
    const canReassign = !isEdit || task.can?.reassign;

    useEffect(() => {
        if (!open) {
            return;
        }

        setErrors({});
        setForm(task
            ? {
                title: task.title || '',
                description: task.description || '',
                assignee_id: task.assignee?.id ?? '',
                status: task.status || 'open',
                priority: task.priority || 'normal',
                due_at: task.due_at || '',
            }
            : EMPTY);
    }, [open, task]);

    const set = (field, value) => setForm((prev) => ({ ...prev, [field]: value }));

    const submit = async () => {
        setBusy(true);
        setErrors({});

        try {
            const payload = {
                title: form.title,
                description: form.description || null,
                status: form.status,
                priority: form.priority,
                due_at: form.due_at || null,
            };

            if (canReassign && form.assignee_id) {
                payload.assignee_id = form.assignee_id;
            }

            let saved;

            if (isEdit) {
                saved = await axios.patch(`/crm/tasks/${task.id}`, payload);
            } else {
                if (entity) {
                    payload.entity_type = entity.type;
                    payload.entity_id = entity.id;
                }
                saved = await axios.post('/crm/tasks', payload);
            }

            toastSuccess(isEdit ? 'Задача сохранена' : 'Задача поставлена');
            onSaved?.(saved.data);
            onClose();
        } catch (e) {
            if (e?.response?.status === 422) {
                setErrors(e.response.data.errors || {});
            } else {
                toastError('Задача не сохранена', e?.response?.data?.message || 'Попробуйте ещё раз.');
            }
        } finally {
            setBusy(false);
        }
    };

    const error = (field) => errors[field]?.[0];

    return (
        <Dialog.Root open={open} onOpenChange={({ open: isOpen }) => !isOpen && onClose()} size="lg" scrollBehavior="inside">
            <Portal>
                <Dialog.Backdrop />
                <Dialog.Positioner>
                    <Dialog.Content>
                        <Dialog.Header>
                            <Dialog.Title>{isEdit ? 'Задача' : 'Новая задача'}</Dialog.Title>
                        </Dialog.Header>

                        <Dialog.Body>
                            <VStack align="stretch" gap={4}>
                                {isEdit && task.entity && (
                                    <Text fontSize="xs" color="fg.muted">
                                        Привязана: {task.entity.label} — {task.entity.url
                                            ? <a href={task.entity.url}>{task.entity.title}</a>
                                            : task.entity.title}
                                    </Text>
                                )}

                                <Field label="Что сделать" required errorText={error('title')} invalid={!!error('title')}>
                                    <Input
                                        value={form.title}
                                        onChange={(e) => set('title', e.target.value)}
                                        placeholder="Например: выставить счёт по заявке"
                                        disabled={!canEditFields}
                                    />
                                </Field>

                                <Field label="Описание" errorText={error('description')} invalid={!!error('description')}>
                                    <Textarea
                                        value={form.description}
                                        onChange={(e) => set('description', e.target.value)}
                                        rows={3}
                                        placeholder="Подробности, если нужны"
                                        disabled={!canEditFields}
                                    />
                                </Field>

                                <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                    <Field label="Исполнитель" required errorText={error('assignee_id')} invalid={!!error('assignee_id')}>
                                        <NativeSelectRoot disabled={!canReassign}>
                                            <NativeSelectField
                                                value={form.assignee_id}
                                                onChange={(e) => set('assignee_id', e.target.value)}
                                            >
                                                <option value="">Выберите сотрудника</option>
                                                {(options?.assignees || []).map((user) => (
                                                    <option key={user.id} value={user.id}>{user.name}</option>
                                                ))}
                                            </NativeSelectField>
                                        </NativeSelectRoot>
                                    </Field>

                                    <Field label="Срок" errorText={error('due_at')} invalid={!!error('due_at')}>
                                        <Input
                                            type="datetime-local"
                                            value={form.due_at}
                                            onChange={(e) => set('due_at', e.target.value)}
                                            disabled={!canEditFields}
                                        />
                                    </Field>

                                    <Field label="Статус">
                                        <NativeSelectRoot disabled={!canEditFields}>
                                            <NativeSelectField
                                                value={form.status}
                                                onChange={(e) => set('status', e.target.value)}
                                            >
                                                {(options?.statuses || []).map((item) => (
                                                    <option key={item.value} value={item.value}>{item.label}</option>
                                                ))}
                                            </NativeSelectField>
                                        </NativeSelectRoot>
                                    </Field>

                                    <Field label="Приоритет">
                                        <NativeSelectRoot disabled={!canEditFields}>
                                            <NativeSelectField
                                                value={form.priority}
                                                onChange={(e) => set('priority', e.target.value)}
                                            >
                                                {(options?.priorities || []).map((item) => (
                                                    <option key={item.value} value={item.value}>{item.label}</option>
                                                ))}
                                            </NativeSelectField>
                                        </NativeSelectRoot>
                                    </Field>
                                </SimpleGrid>

                                {isEdit && (
                                    <>
                                        {can('crm-comments.view') && (
                                            <Box pt={2} borderTopWidth="1px">
                                                <Text fontSize="sm" fontWeight="600" mb={2}>Обсуждение</Text>
                                                <CommentThread
                                                    entityType="task"
                                                    entityId={task.id}
                                                    canCreate={can('crm-comments.create')}
                                                />
                                            </Box>
                                        )}

                                        {can('crm-attachments.view') && (
                                            <Box pt={2} borderTopWidth="1px">
                                                <AttachmentPanel
                                                    entityType="task"
                                                    entityId={task.id}
                                                    canUpload={can('crm-attachments.create')}
                                                    label="Файлы задачи"
                                                />
                                            </Box>
                                        )}
                                    </>
                                )}
                            </VStack>
                        </Dialog.Body>

                        <Dialog.Footer>
                            <HStack gap={2}>
                                <Button variant="outline" onClick={onClose} disabled={busy}>Закрыть</Button>
                                {canEditFields && (
                                    <Button onClick={submit} loading={busy}>
                                        {isEdit ? 'Сохранить' : 'Поставить задачу'}
                                    </Button>
                                )}
                            </HStack>
                        </Dialog.Footer>
                    </Dialog.Content>
                </Dialog.Positioner>
            </Portal>
        </Dialog.Root>
    );
}
