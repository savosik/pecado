import { useEffect, useState } from 'react';
import axios from 'axios';
import { usePage } from '@inertiajs/react';
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
import { Field } from '@/components/ui/field';
import VoiceInput from '@/shared/voice/VoiceInput';
import VoiceTextarea from '@/shared/voice/VoiceTextarea';
import { EntitySelector } from '@/Admin/Components/EntitySelector';
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
 * @param {string} initialTitle — заголовок новой задачи по умолчанию (правится вручную)
 * @param {Function} onSaved — колбэк с сохранённой задачей
 */
export default function TaskDialog({ open, onClose, task = null, entity = null, initialTitle = '', onSaved }) {
    const options = useTaskOptions(open);
    const { can } = usePermission();
    const { auth } = usePage().props;
    const currentUserId = auth?.user?.id ?? null;
    const [form, setForm] = useState(EMPTY);
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    // Привязка новой задачи: тип выбирается селектом, сама запись — поиском.
    // Когда диалог открыт из карточки сущности, привязка приходит пропсом и не выбирается.
    const [linkType, setLinkType] = useState('');
    const [linkEntity, setLinkEntity] = useState(null);

    const isEdit = !!task;
    const canEditFields = !isEdit || task.can?.update;
    const canReassign = !isEdit || task.can?.reassign;

    useEffect(() => {
        if (!open) {
            return;
        }

        setErrors({});
        setLinkType('');
        setLinkEntity(null);
        setForm(task
            ? {
                title: task.title || '',
                description: task.description || '',
                assignee_id: task.assignee?.id ?? '',
                status: task.status || 'open',
                priority: task.priority || 'normal',
                due_at: task.due_at || '',
            }
            // Заголовок-заготовка от вызывающей страницы (например, «Оплата по
            // реализации …»): экономит набор текста, но остаётся обычным полем.
            : { ...EMPTY, title: initialTitle || '' });
    }, [open, task, initialTitle]);

    // Исполнитель новой задачи по умолчанию — тот, кто её ставит: себе задачи
    // ставят чаще, чем коллеге. Ждём справочник, потому что подставлять человека,
    // которого нет в списке (например, админа без доступа в CRM), нельзя —
    // такую задачу бэкенд не примет.
    useEffect(() => {
        if (!open || isEdit || !currentUserId || !options?.assignees) {
            return;
        }

        if (!options.assignees.some((user) => Number(user.id) === Number(currentUserId))) {
            return;
        }

        setForm((prev) => (prev.assignee_id ? prev : { ...prev, assignee_id: String(currentUserId) }));
    }, [open, isEdit, currentUserId, options]);

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
                // Привязка из карточки сущности имеет приоритет над выбранной вручную:
                // диалог там открыт «по этой записи», и менять цель на лету незачем.
                if (entity) {
                    payload.entity_type = entity.type;
                    payload.entity_id = entity.id;
                } else if (linkType && linkEntity) {
                    payload.entity_type = linkType;
                    payload.entity_id = linkEntity.id;
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
        // Клик мимо диалог не закрывает: выпадашка поиска записи рисуется порталом
        // в body, то есть формально «снаружи», и выбор клиента закрывал бы всю форму
        // вместе с набранным текстом. Escape и кнопка «Закрыть» работают как обычно.
        <Dialog.Root
            open={open}
            onOpenChange={({ open: isOpen }) => !isOpen && onClose()}
            size="lg"
            scrollBehavior="inside"
            closeOnInteractOutside={false}
        >
            <Portal>
                <Dialog.Backdrop />
                <Dialog.Positioner>
                    <Dialog.Content>
                        <Dialog.Header>
                            <Dialog.Title>{isEdit ? 'Задача' : 'Новая задача'}</Dialog.Title>
                        </Dialog.Header>

                        <Dialog.Body>
                            <VStack align="stretch" gap={4}>
                                {isEdit && (
                                    <Text fontSize="xs" color="fg.muted">
                                        {task.entity
                                            ? <>Привязана: {task.entity.label} — {task.entity.url
                                                ? <a href={task.entity.url}>{task.entity.title}</a>
                                                : task.entity.title}</>
                                            : 'Без привязки к записи'}
                                    </Text>
                                )}

                                {!isEdit && entity && (
                                    <Text fontSize="xs" color="fg.muted">
                                        Задача будет привязана к текущей записи.
                                    </Text>
                                )}

                                {/* Привязка выбирается только у новой задачи и только когда
                                    диалог открыт не из карточки сущности. Перепривязка
                                    существующей задачи — отдельная история, её API не умеет. */}
                                {!isEdit && !entity && (
                                    <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                        <Field
                                            label="К чему привязать"
                                            helperText="Необязательно — задача может жить сама по себе"
                                            errorText={error('entity_type')}
                                            invalid={!!error('entity_type')}
                                        >
                                            <NativeSelectRoot>
                                                <NativeSelectField
                                                    value={linkType}
                                                    onChange={(e) => {
                                                        setLinkType(e.target.value);
                                                        setLinkEntity(null);
                                                    }}
                                                >
                                                    <option value="">Без привязки</option>
                                                    {(options?.entity_types || []).map((item) => (
                                                        <option key={item.value} value={item.value}>{item.label}</option>
                                                    ))}
                                                </NativeSelectField>
                                            </NativeSelectRoot>
                                        </Field>

                                        {linkType && (
                                            <Field
                                                label="Запись"
                                                errorText={error('entity_id')}
                                                invalid={!!error('entity_id')}
                                            >
                                                <EntitySelector
                                                    value={linkEntity}
                                                    onChange={setLinkEntity}
                                                    searchUrl={route('crm.tasks.entities')}
                                                    searchParams={{ type: linkType }}
                                                    placeholder={linkType === 'client'
                                                        ? 'Имя, email или телефон клиента'
                                                        : 'Номер документа'}
                                                    renderItem={(item) => (
                                                        <Box>
                                                            <Text fontSize="sm">{item.label}</Text>
                                                            {item.sublabel && (
                                                                <Text fontSize="xs" color="fg.muted">{item.sublabel}</Text>
                                                            )}
                                                        </Box>
                                                    )}
                                                />
                                            </Field>
                                        )}
                                    </SimpleGrid>
                                )}

                                <Field label="Что сделать" required errorText={error('title')} invalid={!!error('title')}>
                                    <VoiceInput
                                        value={form.title}
                                        onChange={(value) => set('title', value)}
                                        placeholder="Например: выставить счёт по заявке"
                                        title="Надиктовать задачу"
                                        disabled={!canEditFields}
                                    />
                                </Field>

                                <Field label="Описание" errorText={error('description')} invalid={!!error('description')}>
                                    <VoiceTextarea
                                        value={form.description}
                                        onChange={(value) => set('description', value)}
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
